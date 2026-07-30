<?php
require_once __DIR__ . '/config/database.php';
require_login();

const PAYMENT_PROOF_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
const PAYMENT_PROOF_MAX_BYTES = 3 * 1024 * 1024;

function handle_payment_proof_upload(array $file): array
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Payment proof upload failed.'];
    }
    if ($file['size'] > PAYMENT_PROOF_MAX_BYTES) {
        return [null, 'Payment proof must be smaller than 3MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PAYMENT_PROOF_EXT, true)) {
        return [null, 'Payment proof must be jpg, png, gif, webp or pdf.'];
    }
    $filename = uniqid('proof_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $filename)) {
        return [null, 'Could not save payment proof.'];
    }
    return [$filename, null];
}

$userId = (int) $_SESSION['user_id'];
$cartId = get_or_create_cart($conn, $userId);

$stmt = mysqli_prepare($conn, '
    SELECT ci.id, ci.quantity, p.id AS product_id, p.name, p.price, parent.name AS parent_name
    FROM cart_items ci
    JOIN products p ON p.id = ci.product_id
    LEFT JOIN products parent ON parent.id = p.parent_id
    WHERE ci.cart_id = ?
');
mysqli_stmt_bind_param($stmt, 'i', $cartId);
mysqli_stmt_execute($stmt);
$cartItems = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
foreach ($cartItems as &$cartItem) {
    $cartItem['display_name'] = $cartItem['parent_name'] ? $cartItem['parent_name'] . ' — ' . $cartItem['name'] : $cartItem['name'];
}
unset($cartItem);

if (!$cartItems) {
    redirect('cart');
}

$subtotal = array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $cartItems));

$minOrderTotal = (float) get_setting($conn, 'min_order_total', '0');
if ($minOrderTotal > 0 && $subtotal < $minOrderTotal && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('danger', 'Minimum order is ' . number_format($minOrderTotal) . ' RWF. Add ' . number_format($minOrderTotal - $subtotal) . ' RWF more to your cart to checkout.');
    redirect('cart');
}

$zones = mysqli_query($conn, "SELECT id, name, fee, fee_type FROM delivery_zones WHERE status = 'active' ORDER BY name");
$zoneList = mysqli_fetch_all($zones, MYSQLI_ASSOC);

$paymentMethods = mysqli_query($conn, "SELECT id, name, requires_proof, instructions FROM payment_methods WHERE status = 'active' ORDER BY name");
$paymentMethodList = mysqli_fetch_all($paymentMethods, MYSQLI_ASSOC);

$addresses = mysqli_query($conn, '
    SELECT a.*, z.name AS zone_name, z.fee AS zone_fee, z.fee_type AS zone_fee_type
    FROM addresses a
    LEFT JOIN delivery_zones z ON z.id = a.zone_id
    WHERE a.user_id = ' . $userId . '
    ORDER BY a.id DESC
');
$addressList = mysqli_fetch_all($addresses, MYSQLI_ASSOC);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentMethod = $_POST['payment_method'] ?? '';
    $addressChoice = $_POST['address_choice'] ?? 'new';

    $addressId = null;
    $deliveryFee = 0;
    $deliveryFeeType = 'fixed';

    $validPaymentMethod = null;
    foreach ($paymentMethodList as $pm) {
        if ($pm['name'] === $paymentMethod) {
            $validPaymentMethod = $pm;
            break;
        }
    }
    if (!$validPaymentMethod) {
        $errors[] = 'Please choose a valid payment method.';
    }

    if ($minOrderTotal > 0 && $subtotal < $minOrderTotal) {
        $errors[] = 'Minimum order is ' . number_format($minOrderTotal) . ' RWF. Add ' . number_format($minOrderTotal - $subtotal) . ' RWF more to your cart to checkout.';
    }

    [$paymentProof, $proofError] = handle_payment_proof_upload($_FILES['payment_proof'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if ($proofError) {
        $errors[] = $proofError;
    } elseif ($validPaymentMethod && (int) $validPaymentMethod['requires_proof'] === 1 && !$paymentProof) {
        $errors[] = 'Please upload proof of payment for ' . $validPaymentMethod['name'] . '.';
    }

    if (!$errors) {
        if ($addressChoice === 'existing') {
            $addressId = (int) ($_POST['address_id'] ?? 0);
            $found = null;
            foreach ($addressList as $addr) {
                if ((int) $addr['id'] === $addressId) {
                    $found = $addr;
                    break;
                }
            }
            if (!$found) {
                $errors[] = 'Please choose a valid delivery address.';
            } else {
                $deliveryFeeType = ($found['zone_fee_type'] ?? 'fixed') === 'negotiable' ? 'negotiable' : 'fixed';
                $deliveryFee = $deliveryFeeType === 'negotiable' ? 0 : (float) ($found['zone_fee'] ?? 0);
            }
        } else {
            $zoneId = (int) ($_POST['zone_id'] ?? 0);
            $cell = clean_text($_POST['cell'] ?? '', 100);
            $village = clean_text($_POST['village'] ?? '', 100);
            $address = clean_text($_POST['address'] ?? '', 500);
            $phone = normalize_phone($_POST['phone'] ?? '');

            $zone = null;
            foreach ($zoneList as $z) {
                if ((int) $z['id'] === $zoneId) {
                    $zone = $z;
                    break;
                }
            }

            if (!$zone || !preg_match('/^\+?\d{8,15}$/', $phone)) {
                $errors[] = 'Please choose a delivery zone and provide a valid phone number.';
            } else {
                $deliveryFeeType = $zone['fee_type'];
                $deliveryFee = $deliveryFeeType === 'negotiable' ? 0 : (float) $zone['fee'];
                $zoneName = $zone['name'];
                $stmt = mysqli_prepare($conn, '
                    INSERT INTO addresses (user_id, zone_id, district, cell, village, address, phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                mysqli_stmt_bind_param($stmt, 'iisssss', $userId, $zoneId, $zoneName, $cell, $village, $address, $phone);
                mysqli_stmt_execute($stmt);
                $addressId = mysqli_insert_id($conn);
            }
        }
    }

    if (!$errors) {
        $total = $subtotal;

        $stmt = mysqli_prepare($conn, '
            INSERT INTO orders (user_id, address_id, total, delivery_fee, delivery_fee_type, payment_method, payment_proof, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "pending")
        ');
        mysqli_stmt_bind_param($stmt, 'iiddsss', $userId, $addressId, $total, $deliveryFee, $deliveryFeeType, $paymentMethod, $paymentProof);
        mysqli_stmt_execute($stmt);
        $orderId = mysqli_insert_id($conn);

        foreach ($cartItems as $item) {
            $lineTotal = $item['price'] * $item['quantity'];
            $stmt = mysqli_prepare($conn, '
                INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ');
            mysqli_stmt_bind_param($stmt, 'iiidd', $orderId, $item['product_id'], $item['quantity'], $item['price'], $lineTotal);
            mysqli_stmt_execute($stmt);
        }

        mysqli_query($conn, 'DELETE FROM cart_items WHERE cart_id = ' . $cartId);

        notify_order_placed($conn, $orderId);

        flash_set('success', 'Order placed successfully.');

        if (is_ajax()) {
            json_response(['success' => true, 'redirect' => 'order?id=' . $orderId]);
        }
        redirect('order?id=' . $orderId);
    }

    if ($errors && is_ajax()) {
        json_response(['success' => false, 'errors' => $errors]);
    }
}

$defaultFeeType = $addressList ? ($addressList[0]['zone_fee_type'] ?? 'fixed') : ($zoneList[0]['fee_type'] ?? 'fixed');
$defaultFee = $addressList ? (float) ($addressList[0]['zone_fee'] ?? 0) : (float) ($zoneList[0]['fee'] ?? 0);
$defaultFeeDisplay = $defaultFeeType === 'negotiable' ? 'Negotiable' : number_format($defaultFee);

$pageTitle = 'Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4">Checkout</h3>

<div id="formError">
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-md-7">
        <form method="post" id="checkoutForm" enctype="multipart/form-data">
            <h5>Delivery Address</h5>

            <?php if ($addressList): ?>
                <div class="mb-3">
                    <?php foreach ($addressList as $i => $addr): ?>
                        <div class="form-check">
                            <input class="form-check-input address-radio" type="radio" name="address_choice" value="existing" id="addr<?= $addr['id'] ?>" data-address-id="<?= $addr['id'] ?>" data-fee="<?= (float) ($addr['zone_fee'] ?? 0) ?>" data-fee-type="<?= e($addr['zone_fee_type'] ?? 'fixed') ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="addr<?= $addr['id'] ?>">
                                <?= e(implode(', ', array_filter([$addr['village'], $addr['cell'], $addr['zone_name'] ?? $addr['district']]))) ?>
                                — <?= e($addr['phone']) ?>
                                <?php if (($addr['zone_fee_type'] ?? 'fixed') === 'negotiable'): ?>
                                    <span class="text-muted">(Negotiable delivery)</span>
                                <?php else: ?>
                                    <span class="text-muted">(<?= number_format((float) ($addr['zone_fee'] ?? 0)) ?> RWF delivery)</span>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="address_id" id="selectedAddressId" value="<?= (int) $addressList[0]['id'] ?>">
                    <div class="form-check mt-2">
                        <input class="form-check-input address-radio" type="radio" name="address_choice" value="new" id="addrNew">
                        <label class="form-check-label" for="addrNew">Use a new address</label>
                    </div>
                </div>
            <?php endif; ?>

            <div id="newAddressFields" class="border rounded p-3 mb-3 <?= $addressList ? 'd-none' : '' ?>">
                <?php if (!$addressList): ?><input type="hidden" name="address_choice" value="new"><?php endif; ?>

                <?php if (!$zoneList): ?>
                    <p class="text-danger small mb-2">No delivery zones are currently available. Please contact support.</p>
                <?php endif; ?>

                <div class="row g-2">
                    <div class="col-12 mb-2">
                        <select name="zone_id" id="zoneSelect" class="form-select" required>
                            <option value="">Select delivery zone*</option>
                            <?php foreach ($zoneList as $zone): ?>
                                <option value="<?= (int) $zone['id'] ?>" data-fee="<?= (float) $zone['fee'] ?>" data-fee-type="<?= e($zone['fee_type']) ?>">
                                    <?= e($zone['name']) ?> —
                                    <?= $zone['fee_type'] === 'negotiable' ? 'Negotiable' : number_format((float) $zone['fee']) . ' RWF' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><input type="text" name="cell" class="form-control" placeholder="Cell"></div>
                    <div class="col-6"><input type="text" name="village" class="form-control" placeholder="Village"></div>
                    <div class="col-6"><input type="text" name="phone" class="form-control" placeholder="Phone*" required></div>
                    <div class="col-12"><textarea name="address" class="form-control" placeholder="Additional address details" rows="2"></textarea></div>
                </div>
            </div>

            <h5>Payment Method</h5>
            <?php if (!$paymentMethodList): ?>
                <p class="text-danger small mb-2">No payment methods are currently available. Please contact support.</p>
            <?php endif; ?>
            <select name="payment_method" id="paymentMethodSelect" class="form-select mb-3" <?= $paymentMethodList ? 'required' : 'disabled' ?>>
                <?php foreach ($paymentMethodList as $pm): ?>
                    <option value="<?= e($pm['name']) ?>" data-requires-proof="<?= (int) $pm['requires_proof'] ?>" data-instructions="<?= e($pm['instructions'] ?? '') ?>"><?= e($pm['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div id="paymentInstructions" class="alert alert-info small d-none" style="white-space:pre-line;"></div>

            <div id="paymentProofField" class="mb-3 d-none">
                <label class="form-label">Payment proof (screenshot or receipt)</label>
                <input type="file" name="payment_proof" id="paymentProofInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                <div class="form-text">Required for this payment method. Max 3MB — jpg, png, gif, webp or pdf.</div>
            </div>

            <button type="submit" class="btn btn-dark w-100">Place Order</button>
        </form>
    </div>

    <div class="col-md-5">
        <div class="card p-3">
            <h5>Order Summary</h5>
            <?php foreach ($cartItems as $item): ?>
                <div class="d-flex justify-content-between">
                    <span><?= e($item['display_name']) ?> x<?= (int) $item['quantity'] ?></span>
                    <span><?= number_format($item['price'] * $item['quantity']) ?></span>
                </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between"><span>Subtotal</span><span id="summarySubtotal"><?= number_format($subtotal) ?></span></div>
            <div class="d-flex justify-content-between"><span>Delivery Fee</span><span id="summaryFee"><?= $defaultFeeDisplay ?></span></div>
            <div class="d-flex justify-content-between fw-bold"><span>Total</span><span id="summaryTotal"><?= $defaultFeeType === 'negotiable' ? number_format($subtotal) . ' + delivery (TBD)' : number_format($subtotal + $defaultFee) . ' RWF' ?></span></div>
        </div>
    </div>
</div>

<script>
const subtotal = <?= json_encode($subtotal) ?>;

function updateDeliveryFee() {
    const existingChecked = document.querySelector('input[name="address_choice"][value="existing"]:checked');
    let fee = 0;
    let feeType = 'fixed';

    if (existingChecked) {
        fee = parseFloat(existingChecked.dataset.fee || 0);
        feeType = existingChecked.dataset.feeType || 'fixed';
    } else {
        const zoneSelect = document.getElementById('zoneSelect');
        if (zoneSelect && zoneSelect.selectedOptions.length) {
            fee = parseFloat(zoneSelect.selectedOptions[0].dataset.fee || 0);
            feeType = zoneSelect.selectedOptions[0].dataset.feeType || 'fixed';
        }
    }

    if (feeType === 'negotiable') {
        document.getElementById('summaryFee').textContent = 'Negotiable';
        document.getElementById('summaryTotal').textContent = subtotal.toLocaleString() + ' + delivery (TBD)';
    } else {
        document.getElementById('summaryFee').textContent = fee.toLocaleString();
        document.getElementById('summaryTotal').textContent = (subtotal + fee).toLocaleString() + ' RWF';
    }
}

function toggleNewAddressRequired(isNew) {
    var zoneField = document.getElementById('zoneSelect');
    var phoneField = document.querySelector('#newAddressFields input[name="phone"]');
    if (zoneField) zoneField.required = isNew;
    if (phoneField) phoneField.required = isNew;
}

<?php if ($addressList): ?>
document.querySelectorAll('.address-radio').forEach(function (radio) {
    radio.addEventListener('change', function () {
        document.getElementById('newAddressFields').classList.toggle('d-none', this.value !== 'new');
        toggleNewAddressRequired(this.value === 'new');
        if (this.value === 'existing') {
            document.getElementById('selectedAddressId').value = this.dataset.addressId;
        }
        updateDeliveryFee();
    });
});
toggleNewAddressRequired(false);
<?php endif; ?>

const zoneSelectEl = document.getElementById('zoneSelect');
if (zoneSelectEl) {
    zoneSelectEl.addEventListener('change', updateDeliveryFee);
}

const paymentMethodSelectEl = document.getElementById('paymentMethodSelect');
const paymentProofField = document.getElementById('paymentProofField');
const paymentProofInput = document.getElementById('paymentProofInput');
const paymentInstructionsEl = document.getElementById('paymentInstructions');

function syncPaymentProofField() {
    if (!paymentMethodSelectEl || !paymentMethodSelectEl.selectedOptions.length) return;
    const selected = paymentMethodSelectEl.selectedOptions[0];
    const requiresProof = selected.dataset.requiresProof === '1';
    paymentProofField.classList.toggle('d-none', !requiresProof);
    paymentProofInput.required = requiresProof;
    if (!requiresProof) paymentProofInput.value = '';

    const instructions = selected.dataset.instructions || '';
    paymentInstructionsEl.textContent = instructions;
    paymentInstructionsEl.classList.toggle('d-none', !instructions);
}

if (paymentMethodSelectEl) {
    paymentMethodSelectEl.addEventListener('change', syncPaymentProofField);
    syncPaymentProofField();
}

document.getElementById('checkoutForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Placing order…');
    postAjax(window.location.pathname, new FormData(form)).then(res => {
        if (res.success) {
            window.location.href = res.redirect;
            return;
        }
        restore();
        document.getElementById('formError').innerHTML = res.errors.map(msg => '<div class="alert alert-danger">' + msg + '</div>').join('');
    }).catch(() => {
        restore();
        document.getElementById('formError').innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
