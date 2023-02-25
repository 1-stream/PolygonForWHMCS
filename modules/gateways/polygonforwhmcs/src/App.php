<?php

namespace PolygonForWHMCS;

use PolygonForWHMCS\Exceptions\NoAddressAvailable;
use PolygonForWHMCS\Models\PolygonForWHMCSInvoice;
use PolygonForWHMCS\Models\Invoice;
use PolygonForWHMCS\Models\Transaction;
use Carbon\Carbon;
use WHMCS\Database\Capsule;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use RuntimeException;
use Smarty;
use Throwable;

class App
{
    /**
     * USDT Addresses.
     *
     * @var string[]
     */
    protected $addresses = [];

    /**
     * The payment gateway fields.
     *
     * @var string[]
     */
    protected $config = [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PolygonForWHMCS',
        ],
        'addresses' => [
            'FriendlyName' => 'USDT Addresses',
            'Type' => 'textarea',
            'Rows' => '20',
            'Cols' => '30',
        ],
        'timeout' => [
            'FriendlyName' => 'Timeout',
            'Type' => 'text',
            'Value' => 30,
            'Description' => 'Minutes'
        ],
        'apikey' => [
            'FriendlyName' => 'Polygonscan ApiKey',
            'Type' => 'text',
            // 'Value' => 30,
            'Description' => ''
        ]
    ];

    /**
     * Smarty template engine.
     *
     * @var Smarty
     */
    protected $smarty;

    /**
     * Create a new instance.
     *
     * @param   string  $addresses
     * @param   bool    $configMode
     *
     * @return  void
     */
    public function __construct(array $params = [])
    {
        if (!function_exists('getGatewayVariables')) {
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'init.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/gatewayfunctions.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/invoicefunctions.php';
        } else {
            if (empty($params) && !$configMode) {
                try {
                    $params = getGatewayVariables('polygonforwhmcs');
                } catch (Throwable $e) {
                }
            }
        }

        $this->timeout = $params['timeout'] ?? 30;
        $this->addresses = array_filter(preg_split("/\r\n|\n|\r/", $params['addresses'] ?? ''));
        $this->apikey = $params['apikey'];

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(Polygon_PAY_ROOT . DIRECTORY_SEPARATOR . 'templates');
        $this->smarty->setCompileDir(WHMCS_ROOT . DIRECTORY_SEPARATOR . 'templates_c');
    }

    /**
     * Fetch smarty renderred template.
     *
     * @param   string  $viewName
     * @param   array   $arguments
     *
     * @return  string
     */
    protected function view(string $viewName, array $arguments = [])
    {
        foreach ($arguments as $name => $variable) {
            $this->smarty->assign($name, $variable);
        }

        return $this->smarty->fetch($viewName);
    }

    /**
     * Install PolygonForWHMCS.
     *
     * @return  string[]
     */
    public function install()
    {
        $this->runMigrations();

        return $this->config;
    }

    /**
     * Run beefy asian pay migrations.
     *
     * @return  void
     */
    protected function runMigrations()
    {
        $migrationPath = __DIR__ . DIRECTORY_SEPARATOR . 'Migrations';
        $migrations = array_diff(scandir($migrationPath), ['.', '..']);

        foreach ($migrations as $migration) {
            require_once $migrationPath . DIRECTORY_SEPARATOR . $migration;

            $migrationName = str_replace('.php', '', $migration);

            (new $migrationName)->execute();
        }
    }

    /**
     * Render payment html.
     *
     * @param   array  $params
     *
     * @return  mixed
     */
    public function render(array $params)
    {
        switch ($_GET['act']) {
            case 'invoice_status':
                $this->renderInvoiceStatusJson($params);
            case 'create':
                $this->createPolygonForWHMCSInvoice($params);
            default:
                return $this->renderPaymentHTML($params);
        }
    }

    /**
     * Create beefy asian pay invoice.
     *
     * @param   array  $params
     *
     * @return  void
     */
    protected function createPolygonForWHMCSInvoice(array $params)
    {
        try {
            $invoice = (new Invoice())->find($params['invoiceid']);

            if (mb_strtolower($invoice['status']) === 'paid') {
                $this->json([
                    'status' => false,
                    'error' => 'The invoice has been paid in full.'
                ]);
            } else {
                $address = $this->getAvailableAddress($params['invoiceid']);
                // $start_block = $this->getNowPolygonBlock();

                $this->json([
                    'status' => true,
                    'address' => $address,
                    // 'start_block' => $start_block,
                ]);
            }
        } catch (Throwable $e) {
            $this->json([
                'status' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get then invoice status json.
     *
     * @param   array   $params
     *
     * @return  void
     */
    protected function renderInvoiceStatusJson(array $params)
    {
        $polygonInvoice = (new PolygonForWHMCSInvoice())->firstValidByInvoiceId($params['invoiceid']);
        if ($polygonInvoice) {
            $invoice = (new Invoice())->with('transactions')->find($params['invoiceid']);
            $this->checkTransaction($polygonInvoice);
            $polygonInvoice = $polygonInvoice->refresh();

            if (mb_strtolower($invoice['status']) === 'unpaid') {
                if ($polygonInvoice['expires_on']->subMinutes(3)->lt(Carbon::now())) {
                    $polygonInvoice->renew($this->timeout);
                }

                $polygonInvoice = $polygonInvoice->refresh();
            }

            $json = [
                'status' => $invoice['status'],
                'amountin' => $invoice['transactions']->sum('amountin'),
                'valid_till' => $polygonInvoice['expires_on']->toDateTimeString(),
            ];

            $this->json($json);
        }

        $this->json([
            'status' => false,
            'error' => 'invoice does not exists',
        ]);
    }

    /**
     * Responed with JSON.
     *
     * @param   array  $json
     *
     * @return  void
     */
    protected function json(array $json)
    {
        $json = json_encode($json);
        header('Content-Type: application/json');
        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            exit();
        }
    }

    /**
     * Render pay with usdt html.
     *
     * @param   array   $params
     *
     * @return  string
     */
    protected function renderPaymentHTML(array $params): string
    {
        $polygonInvoice = new PolygonForWHMCSInvoice();

        if ($validAddress = $polygonInvoice->validInvoice($params['invoiceid'])) {
            $validAddress->renew($this->timeout);
            $validTill = Carbon::now()->addMinutes($this->timeout)->toDateTimeString();

            $Currencyrate = Capsule::table("tblcurrencies")->where("code", "USD")->value("rate");
            $Currencydefault = Capsule::table("tblcurrencies")->where("code", "USD")->value("default");
            if ($Currencydefault == '1') {
                $amount = $params['amount'];
            } else {
                $amount = $params['amount'] * $Currencyrate;
            }
            return $this->view('payment.tpl', [
                'address' => $validAddress['to_address'],
                'amount' => $amount,
                'validTill' => $validTill,
            ]);
        } else {
            return $this->view('pay_with_usdt.tpl');
        }
    }

    /**
     * Remove expired invoices.
     *
     * @return  void
     */
    public function cron()
    {
        $this->checkPaidInvoice();

        (new PolygonForWHMCSInvoice())->markExpiredInvoiceAsReleased();
    }

    /**
     * Check paid invoices.
     *
     * @return  void
     */
    protected function checkPaidInvoice()
    {
        $invoices = (new PolygonForWHMCSInvoice())->getValidInvoices();

        $invoices->each(function ($invoice) {
            $this->checkTransaction($invoice);
        });
    }

    /**
     * Check USDT Transaction.
     *
     * @param   PolygonForWHMCSInvoice  $invoice
     *
     * @return  void
     */
    protected function checkTransaction(PolygonForWHMCSInvoice $invoice)
    {
        $this->getTransactions($invoice['to_address'], $invoice['start_block'])
            ->each(function ($transaction) use ($invoice) {
                $whmcsTransaction = (new Transaction())->firstByTransId($transaction['hash']);
                $whmcsInvoice = Invoice::find($invoice['invoice_id']);
                // If current invoice has been paid ignore it.
                if ($whmcsTransaction) {
                    return;
                }

                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    return;
                }

                if (mb_strtolower($transaction['to']) != mb_strtolower($invoice['to_address']) || $transaction['tokenSymbol'] != "USDT") {
                    return;
                }

                $Currencyrate = Capsule::table("tblcurrencies")->where("code", "USD")->value("rate");
                $Currencydefault = Capsule::table("tblcurrencies")->where("code", "USD")->value("default");
                if ($Currencydefault == '1') {
                    $actualAmount = $transaction['value'] / 1000000;
                } else {
                    $actualAmount = ($transaction['value'] / 1000000) / $Currencyrate;
                }

                AddInvoicePayment(
                    $invoice['invoice_id'], // Invoice id
                    $transaction['hash'],
                    // Transaction id
                    $actualAmount,
                    // Paid amount
                    0,
                    // Transaction fee
                    'polygonforwhmcs' // Gateway
    
                );

                logTransaction('PolygonForWHMCS', $transaction, 'Successfully Paid');

                $whmcsInvoice = $whmcsInvoice->refresh();
                // If the invoice has been paid in full, release the address, otherwise renew it.
                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    $invoice->markAsPaid($transaction['from'], $transaction['hash']);
                } else {
                    $invoice->renew($this->timeout);
                }
            });
    }

    /**
     * Get TRC 20 address transactions.
     *
     * @param   string  $address
     * @param   int  $startblock
     *
     * @return  Collection
     */
    protected function getTransactions(string $address, int $startblock): Collection
    {
        $http = new Client([
            'base_uri' => 'https://api.polygonscan.com',
            'timeout' => 30,
        ]);

        $response = $http->get("/api", [
            'query' => [
                'module' => "account",
                'action' => "tokentx",
                'address' => $address,
                'page' => 1,
                'offset' => 5,
                'startblock' => $startblock,
                'sort' => "desc",
                'apikey' => $this->apikey,
            ],
        ]);
        $response = json_decode($response->getBody()->getContents(), true);
        // var_dump(new Collection($response['result']));
        return new Collection($response['result']);
    }

    /**
     * Get an available usdt address.
     *
     * @param   int     $invoiceId
     *
     * @return  string
     *
     * @throws  NoAddressAvailable
     * @throws  RuntimeException
     */
    protected function getAvailableAddress(int $invoiceId): string
    {
        $polygonInvoice = new PolygonForWHMCSInvoice();

        if ($polygonInvoice->firstValidByInvoiceId($invoiceId)) {
            throw new RuntimeException("The invoice has been associated with a USDT address please refresh the invoice page.");
        }

        $inUseAddresses = $polygonInvoice->inUse()->get(['to_address']);

        $availableAddresses = array_values(array_diff($this->addresses, $inUseAddresses->pluck('to_address')->toArray()));

        if (count($availableAddresses) <= 0) {
            throw new NoAddressAvailable('no available address please try again later.');
        }

        $address = $availableAddresses[array_rand($availableAddresses)];
        $polygonInvoice->associate($address, $invoiceId, $this->timeout, $this->getNowPolygonBlock());

        return $address;
    }

    protected function getNowPolygonBlock(): int
    {
        $http = new Client([
            'base_uri' => 'https://api.polygonscan.com',
            'timeout' => 30,
        ]);

        $response = $http->get("/api", [
            'query' => [
                'module' => "block",
                'action' => "getblocknobytime",
                'timestamp' => time(),
                'closest' => 'before',
                'apikey' => $this->apikey,
            ],
        ]);
        $response = json_decode($response->getBody()->getContents(), true);

        return $response['result'];
    }
}