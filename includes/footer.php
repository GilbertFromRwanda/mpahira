</main>
<?php
global $conn;
$footerWhatsapp = get_setting($conn, 'whatsapp_phone', '');
$footerContactPhone = get_setting($conn, 'contact_phone', '');
?>
<footer class="bg-dark text-light-50 text-center py-3 mt-5">
    <?php if ($footerWhatsapp || $footerContactPhone): ?>
        <div class="mb-2">
            <?php if ($footerWhatsapp): ?>
                <a class="text-white-50 me-3" href="https://wa.me/<?= e(preg_replace('/\D/', '', $footerWhatsapp)) ?>" target="_blank" rel="noopener">WhatsApp: <?= e($footerWhatsapp) ?></a>
            <?php endif; ?>
            <?php if ($footerContactPhone): ?>
                <a class="text-white-50" href="tel:<?= e($footerContactPhone) ?>">Call: <?= e($footerContactPhone) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <small class="text-white-50">&copy; <?= date('Y') ?> Mpahira. All rights reserved.</small>
</footer>

<?php if ($footerWhatsapp): ?>
<a class="whatsapp-fab" href="https://wa.me/<?= e(preg_replace('/\D/', '', $footerWhatsapp)) ?>" target="_blank" rel="noopener" title="Chat with us on WhatsApp" aria-label="Chat with us on WhatsApp">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="30" height="30" fill="#fff">
        <path d="M16.004 3C9.373 3 4 8.373 4 15.004c0 2.35.687 4.54 1.87 6.386L4 29l7.79-1.84a11.94 11.94 0 0 0 4.214.758h.005c6.63 0 12.003-5.373 12.003-12.004C28.012 8.373 22.638 3 16.004 3zm0 21.807a9.77 9.77 0 0 1-4.98-1.362l-.357-.212-4.622 1.091 1.115-4.507-.233-.372a9.76 9.76 0 0 1-1.53-5.24c0-5.4 4.395-9.795 9.81-9.795 5.412 0 9.808 4.394 9.808 9.795 0 5.402-4.396 9.802-9.81 9.802zm5.386-7.34c-.294-.148-1.74-.858-2.01-.956-.27-.099-.467-.148-.664.148-.196.295-.762.955-.934 1.152-.172.196-.344.221-.638.074-.294-.148-1.242-.457-2.366-1.46-.874-.78-1.464-1.744-1.636-2.038-.172-.295-.018-.454.13-.601.134-.133.294-.344.442-.516.147-.172.196-.295.294-.492.098-.196.05-.369-.024-.516-.074-.148-.664-1.6-.91-2.192-.24-.577-.484-.499-.664-.508l-.565-.01c-.196 0-.516.074-.786.369-.27.295-1.03 1.006-1.03 2.454 0 1.447 1.055 2.845 1.202 3.041.147.196 2.077 3.17 5.033 4.443.703.303 1.252.484 1.68.62.706.225 1.348.193 1.856.117.566-.085 1.74-.712 1.985-1.4.245-.688.245-1.278.172-1.4-.073-.123-.27-.196-.565-.344z"/>
    </svg>
</a>
<?php endif; ?>
</body>
</html>
