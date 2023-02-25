<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.8/dist/clipboard.min.js"></script>
<style>
    .payment-btn-container {
        display: flex;
        justify-content: center;
    }

    #qrcode {
        display: flex;
        width: 100%;
        justify-content: center;
    }

    .address {
        width: 100%;
        border: 1px solid #eee;
        padding: 5px;
        border-radius: 4px;
    }

    .copy-botton {
        width: 100%;
    }
    .copy-botton .btn {
        width: 100%;
    }
</style>

<div style="width: 250px">
    <div id="qrcode"></div>
    <p>Please pay $ {$amount} USDT</span><br/>
    <b>USDT (Polygon) only, Other tokens are non-refundable</b><br/>
    <span>Valid till <span id="valid-till">{$validTill}</span></p>
    <p class="usdt-addr">
        <input id="address" class="address" value="{$address}"></span>

        <div class="copy-botton">
            <button id="clipboard-btn" class="btn btn-primary" type="button" data-clipboard-target="#address">COPY</button>
        </div>
    </p>
</div>

<script>
    const clipboard = new ClipboardJS('#clipboard-btn')
    clipboard.on('success', () => {
        $('#clipboard-btn').text('COPIED')
        setTimeout(() => {
            $('#clipboard-btn').text('COPY')
        }, 500);
    })

    new QRCode(document.querySelector('#qrcode'), {
        text: "{$address}",
        width: 200,
        height: 200,
    })

    $('#clipboard-btn').hover(() => {
        $('#clipboard-btn').text('COPY')
    })

    window.localStorage.removeItem('whmcs_usdt_invoice')
    setInterval(() => {
        $('#clipboard-btn').text('UPDATING')
        fetch(window.location.href + '&act=invoice_status')
            .then(r => r.json())
            .then(r => {
                const previous = JSON.parse(window.localStorage.getItem(`whmcs_usdt_invoice`) || '{}')
                window.localStorage.setItem('whmcs_usdt_invoice', JSON.stringify(r))
                if (r.status.toLowerCase() === 'paid' || (previous.amountin !== undefined && previous?.amountin !== r.amountin)) {
                    $('#clipboard-btn').text('ADDING PMT')

                    setTimeout(() => {
                        window.location.reload(true)
                    }, 1000);
                } else if (!r.status) {
                    alert(r.error)
                } else {
                    document.querySelector('#valid-till').innerHTML = r.valid_till
                }

                setTimeout(() => {
                    $('#clipboard-btn').text('UPDATED')
                    setTimeout(() => {
                        $('#clipboard-btn').text('COPY')
                    }, 1000)
                }, 1000)
            })
            .catch(e => window.location.reload(true))

    }, 15000);
</script>
