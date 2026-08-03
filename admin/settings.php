<?php
require_once __DIR__ . '/../config/database.php';
require_super();

// ---------- Delivery zones ----------

function fetch_zones(mysqli $conn): array
{
    $result = mysqli_query($conn, '
        SELECT z.id, z.name, z.fee, z.fee_type, z.status, COUNT(a.id) AS address_count
        FROM delivery_zones z
        LEFT JOIN addresses a ON a.zone_id = z.id
        GROUP BY z.id, z.name, z.fee, z.fee_type, z.status
        ORDER BY z.name
    ');
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function render_zone_rows(array $zones): string
{
    if (!$zones) {
        return '<tr><td colspan="4" class="text-center text-muted">No delivery zones yet.</td></tr>';
    }

    ob_start();
    foreach ($zones as $zone): ?>
        <tr data-name="<?= e(strtolower($zone['name'])) ?>" data-status="<?= e($zone['status']) ?>">
            <td><?= e($zone['name']) ?></td>
            <td>
                <?php if ($zone['fee_type'] === 'negotiable'): ?>
                    <span class="badge bg-warning text-dark">Negotiable</span>
                    <?php if ((float) $zone['fee'] > 0): ?>
                        <span class="text-muted small">(from <?= number_format((float) $zone['fee']) ?> RWF)</span>
                    <?php endif; ?>
                <?php else: ?>
                    <?= number_format((float) $zone['fee']) ?> RWF
                <?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $zone['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e($zone['status']) ?></span></td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-dark zone-edit-btn" data-id="<?= (int) $zone['id'] ?>">Edit</button>
                <?php if ((int) $zone['address_count'] === 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger zone-delete-btn" data-id="<?= (int) $zone['id'] ?>">Delete</button>
                <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled title="In use by saved addresses">Delete</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

// ---------- Payment methods ----------

function fetch_payment_methods(mysqli $conn): array
{
    $result = mysqli_query($conn, '
        SELECT m.id, m.name, m.status, m.requires_proof, m.instructions, COUNT(o.id) AS order_count
        FROM payment_methods m
        LEFT JOIN orders o ON o.payment_method = m.name
        GROUP BY m.id, m.name, m.status, m.requires_proof, m.instructions
        ORDER BY m.name
    ');
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function render_payment_method_rows(array $methods): string
{
    if (!$methods) {
        return '<tr><td colspan="5" class="text-center text-muted">No payment methods yet.</td></tr>';
    }

    ob_start();
    foreach ($methods as $method): ?>
        <tr data-name="<?= e(strtolower($method['name'])) ?>" data-status="<?= e($method['status']) ?>">
            <td><?= e($method['name']) ?></td>
            <td><span class="badge bg-<?= $method['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e($method['status']) ?></span></td>
            <td><?php if ((int) $method['requires_proof']): ?><span class="badge bg-info text-dark">Required</span><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
            <td class="small" style="max-width:220px;white-space:pre-line;">
                <?php $instructions = trim((string) $method['instructions']); ?>
                <?= $instructions !== '' ? e($instructions) : '<span class="text-muted">&mdash;</span>' ?>
            </td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-dark method-edit-btn" data-id="<?= (int) $method['id'] ?>">Edit</button>
                <?php if ((int) $method['order_count'] === 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger method-delete-btn" data-id="<?= (int) $method['id'] ?>">Delete</button>
                <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled title="In use by orders">Delete</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

// ---------- POST handling ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    $message = '';
    $type = 'success';

    if ($section === 'zone') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $name = trim($_POST['name'] ?? '');
            $feeType = ($_POST['fee_type'] ?? 'fixed') === 'negotiable' ? 'negotiable' : 'fixed';
            $fee = (float) ($_POST['fee'] ?? 0);
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            if ($name === '' || $fee < 0) {
                $type = 'danger';
                $message = 'Zone name and a valid fee are required.';
            } elseif ($feeType === 'fixed' && $fee <= 0) {
                $type = 'danger';
                $message = 'A fixed-fee zone needs a delivery fee greater than 0.';
            } elseif ($action === 'add') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO delivery_zones (name, fee, fee_type, status) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'sdss', $name, $fee, $feeType, $status);
                mysqli_stmt_execute($stmt);
                $message = 'Delivery zone added.';
            } else {
                $id = (int) $_POST['id'];
                $stmt = mysqli_prepare($conn, 'UPDATE delivery_zones SET name = ?, fee = ?, fee_type = ?, status = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'sdssi', $name, $fee, $feeType, $status, $id);
                mysqli_stmt_execute($stmt);
                $message = 'Delivery zone updated.';
            }
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            $stmt = mysqli_prepare($conn, 'DELETE FROM delivery_zones WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $message = 'Delivery zone deleted.';
        }

        if (is_ajax()) {
            $zones = fetch_zones($conn);
            json_response([
                'success' => $type === 'success',
                'message' => $message,
                'rows' => render_zone_rows($zones),
                'zones' => $zones,
            ]);
        }

        flash_set($type, $message);
        redirect('settings?tab=zones');
    }

    if ($section === 'payment_method') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $name = trim($_POST['name'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $requiresProof = isset($_POST['requires_proof']) ? 1 : 0;
            $instructions = trim($_POST['instructions'] ?? '');
            $instructionsToStore = $instructions !== '' ? $instructions : null;

            if ($name === '') {
                $type = 'danger';
                $message = 'A payment method name is required.';
            } elseif ($action === 'add') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO payment_methods (name, status, requires_proof, instructions) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'ssis', $name, $status, $requiresProof, $instructionsToStore);
                mysqli_stmt_execute($stmt);
                $message = 'Payment method added.';
            } else {
                $id = (int) $_POST['id'];
                $stmt = mysqli_prepare($conn, 'UPDATE payment_methods SET name = ?, status = ?, requires_proof = ?, instructions = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'ssisi', $name, $status, $requiresProof, $instructionsToStore, $id);
                mysqli_stmt_execute($stmt);
                $message = 'Payment method updated.';
            }
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            $stmt = mysqli_prepare($conn, 'DELETE FROM payment_methods WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $message = 'Payment method deleted.';
        }

        if (is_ajax()) {
            $methods = fetch_payment_methods($conn);
            json_response([
                'success' => $type === 'success',
                'message' => $message,
                'rows' => render_payment_method_rows($methods),
                'methods' => $methods,
            ]);
        }

        flash_set($type, $message);
        redirect('settings?tab=payment-methods');
    }

    if ($section === 'general') {
        $minOrderTotal = (float) ($_POST['min_order_total'] ?? 0);
        $whatsappPhone = trim($_POST['whatsapp_phone'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');

        if ($minOrderTotal < 0) {
            $type = 'danger';
            $message = 'Minimum order total cannot be negative.';
        } else {
            set_setting($conn, 'min_order_total', (string) $minOrderTotal);
            set_setting($conn, 'whatsapp_phone', $whatsappPhone);
            set_setting($conn, 'contact_phone', $contactPhone);
            $message = 'Settings updated.';
        }

        if (is_ajax()) {
            json_response(['success' => $type === 'success', 'message' => $message]);
        }

        flash_set($type, $message);
        redirect('settings?tab=general');
    }
}

$zones = fetch_zones($conn);
$methods = fetch_payment_methods($conn);
$minOrderTotal = (float) get_setting($conn, 'min_order_total', '0');
$whatsappPhone = get_setting($conn, 'whatsapp_phone', '');
$contactPhone = get_setting($conn, 'contact_phone', '');
$activeTab = in_array($_GET['tab'] ?? '', ['general', 'zones', 'payment-methods'], true) ? $_GET['tab'] : 'general';

$pageTitle = 'Settings';
$flash = flash_get();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/nav.php';
?>

<style>
.settings-header { display: flex; align-items: center; gap: .85rem; }
.settings-header-icon {
    width: 46px; height: 46px; border-radius: var(--radius-sm);
    background: var(--color-primary-light); color: var(--color-primary-dark);
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0;
}
.settings-nav-card { border-radius: var(--radius-md); }
.settings-nav { gap: .3rem; }
.settings-nav .settings-nav-link {
    display: flex; align-items: center; gap: .75rem; width: 100%; text-align: left;
    border: none; background: transparent; border-radius: var(--radius-sm);
    padding: .65rem .8rem; color: var(--color-text); transition: background-color .15s ease, color .15s ease;
}
.settings-nav .settings-nav-link:hover { background-color: var(--color-primary-light); }
.settings-nav .settings-nav-link.active { background-color: var(--color-primary); color: #fff; }
.settings-nav .settings-nav-link.active .settings-nav-desc { color: rgba(255, 255, 255, .85); }
.settings-nav-icon { font-size: 1.2rem; line-height: 1; width: 26px; text-align: center; flex-shrink: 0; }
.settings-nav-title { display: block; font-size: .92rem; font-weight: 600; }
.settings-nav-desc { display: block; font-size: .74rem; font-weight: 500; color: var(--color-text-muted); }
.settings-section-card { border-radius: var(--radius-md); }
.settings-section-header { display: flex; align-items: flex-start; gap: .7rem; }
.settings-section-icon {
    width: 38px; height: 38px; border-radius: 10px; background: var(--color-primary-light);
    display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;
}
.settings-form-card { max-width: 560px; }
</style>

<div id="formMessage">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
</div>

<div class="settings-header mb-4">
    <div class="settings-header-icon">&#9881;&#65039;</div>
    <div>
        <h1 class="h4 mb-1">Settings</h1>
        <p class="text-muted mb-0">Configure ordering rules, delivery zones, and payment methods for your store.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-3">
        <div class="card settings-nav-card p-2">
            <div class="nav flex-column settings-nav" id="settingsTabs" role="tablist" aria-orientation="vertical">
                <button class="settings-nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-general" type="button">
                    <span class="settings-nav-icon">&#9881;&#65039;</span>
                    <span>
                        <span class="settings-nav-title">General</span>
                        <span class="settings-nav-desc">Ordering &amp; contact</span>
                    </span>
                </button>
                <button class="settings-nav-link <?= $activeTab === 'zones' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-zones" type="button">
                    <span class="settings-nav-icon">&#128666;</span>
                    <span>
                        <span class="settings-nav-title">Delivery Zones</span>
                        <span class="settings-nav-desc"><?= count($zones) ?> zone<?= count($zones) === 1 ? '' : 's' ?></span>
                    </span>
                </button>
                <button class="settings-nav-link <?= $activeTab === 'payment-methods' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tab-payment-methods" type="button">
                    <span class="settings-nav-icon">&#128179;</span>
                    <span>
                        <span class="settings-nav-title">Payment Methods</span>
                        <span class="settings-nav-desc"><?= count($methods) ?> method<?= count($methods) === 1 ? '' : 's' ?></span>
                    </span>
                </button>
            </div>
        </div>
    </div>

    <div class="col-md-9">
        <div class="tab-content">

            <div class="tab-pane fade <?= $activeTab === 'general' ? 'show active' : '' ?>" id="tab-general">
                <div class="settings-form-card">
                    <form id="settingsForm">
                        <input type="hidden" name="section" value="general">

                        <div class="card settings-section-card p-3 p-md-4 mb-4">
                            <div class="settings-section-header mb-3">
                                <span class="settings-section-icon">&#128722;</span>
                                <div>
                                    <h5 class="mb-0">Ordering</h5>
                                    <div class="text-muted small">Rules applied at checkout</div>
                                </div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Minimum order total (RWF)</label>
                                <input type="number" step="0.01" min="0" name="min_order_total" class="form-control" value="<?= e((string) $minOrderTotal) ?>">
                                <div class="form-text">Customers can't place an order until their cart subtotal reaches this amount. Set to 0 to disable.</div>
                            </div>
                        </div>

                        <div class="card settings-section-card p-3 p-md-4 mb-4">
                            <div class="settings-section-header mb-3">
                                <span class="settings-section-icon">&#128222;</span>
                                <div>
                                    <h5 class="mb-0">Contact</h5>
                                    <div class="text-muted small">Shown to customers in the site footer</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">WhatsApp number</label>
                                <input type="text" name="whatsapp_phone" class="form-control" placeholder="e.g. 250788123456" value="<?= e($whatsappPhone) ?>">
                                <div class="form-text">Shown as a WhatsApp chat link in the site footer. Include the country code, no spaces or symbols.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Contact phone</label>
                                <input type="text" name="contact_phone" class="form-control" placeholder="e.g. 0788123456" value="<?= e($contactPhone) ?>">
                                <div class="form-text">Shown in the site footer for customers to call.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-dark px-4">Save changes</button>
                    </form>
                </div>
            </div>

            <div class="tab-pane fade <?= $activeTab === 'zones' ? 'show active' : '' ?>" id="tab-zones">
                <div class="card settings-section-card p-3 p-md-4">
                    <div class="settings-section-header mb-3">
                        <span class="settings-section-icon">&#128666;</span>
                        <div>
                            <h5 class="mb-0">Delivery Zones</h5>
                            <div class="text-muted small">Only <strong>active</strong> zones are offered to customers at checkout. A <strong>negotiable</strong> zone lets the customer place the order without a fixed delivery fee &mdash; you agree the amount with them directly.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                        <div class="d-flex gap-2">
                            <input type="text" id="zoneSearch" class="form-control" placeholder="Search zones..." autocomplete="off" style="max-width:220px;">
                            <select id="zoneStatusFilter" class="form-select" style="max-width:160px;">
                                <option value="">All statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-dark text-nowrap" id="openAddZoneModalBtn" data-bs-toggle="modal" data-bs-target="#zoneModal">+ Add Zone</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered bg-white align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Fee</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="zoneRows"><?= render_zone_rows($zones) ?></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $activeTab === 'payment-methods' ? 'show active' : '' ?>" id="tab-payment-methods">
                <div class="card settings-section-card p-3 p-md-4">
                    <div class="settings-section-header mb-3">
                        <span class="settings-section-icon">&#128179;</span>
                        <div>
                            <h5 class="mb-0">Payment Methods</h5>
                            <div class="text-muted small">Only <strong>active</strong> payment methods are offered to customers at checkout.</div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                        <div class="d-flex gap-2">
                            <input type="text" id="methodSearch" class="form-control" placeholder="Search payment methods..." autocomplete="off" style="max-width:220px;">
                            <select id="methodStatusFilter" class="form-select" style="max-width:160px;">
                                <option value="">All statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-dark text-nowrap" id="openAddMethodModalBtn" data-bs-toggle="modal" data-bs-target="#methodModal">+ Add Payment Method</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered bg-white align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Payment Proof</th>
                                    <th>Send-to Info</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="methodRows"><?= render_payment_method_rows($methods) ?></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Delivery zone modal -->
<div class="modal fade" id="zoneModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="zoneFormTitle">Add Delivery Zone</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="zoneForm">
                    <input type="hidden" name="section" value="zone">
                    <input type="hidden" name="action" id="zoneFormAction" value="add">
                    <input type="hidden" name="id" id="zoneFormId" value="">
                    <div class="mb-3">
                        <label class="form-label">Zone name</label>
                        <input type="text" name="name" id="zoneFormName" class="form-control" placeholder="e.g. Gasabo" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fee type</label>
                        <select name="fee_type" id="zoneFormFeeType" class="form-select">
                            <option value="fixed">Fixed fee</option>
                            <option value="negotiable">Negotiable</option>
                        </select>
                    </div>
                    <div class="mb-3" id="feeInputGroup">
                        <label class="form-label" id="feeLabel">Delivery fee (RWF)</label>
                        <input type="number" step="0.01" min="0" name="fee" id="zoneFormFee" class="form-control" required>
                        <div class="form-text" id="feeHelp"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="zoneFormStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-dark w-100" id="zoneFormSubmitBtn">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Payment method modal -->
<div class="modal fade" id="methodModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="methodFormTitle">Add Payment Method</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="methodForm">
                    <input type="hidden" name="section" value="payment_method">
                    <input type="hidden" name="action" id="methodFormAction" value="add">
                    <input type="hidden" name="id" id="methodFormId" value="">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="methodFormName" class="form-control" placeholder="e.g. Mobile Money" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="methodFormStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="requires_proof" id="methodFormRequiresProof" class="form-check-input" value="1">
                        <label class="form-check-label" for="methodFormRequiresProof">Require payment proof upload</label>
                        <div class="form-text">Customer must upload a screenshot/receipt at checkout when this method is selected.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Where to send the amount</label>
                        <textarea name="instructions" id="methodFormInstructions" class="form-control" rows="3" placeholder="e.g. MTN MoMo: *182*1*1*0788123456# or dial 0788123456, Name: Mpahira Ltd"></textarea>
                        <div class="form-text">Shown to the customer at checkout when they select this payment method (MoMo number, bank account, agent, etc).</div>
                    </div>
                    <button type="submit" class="btn btn-dark w-100" id="methodFormSubmitBtn">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ---------- General settings ----------
document.getElementById('settingsForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const restore = setFormLoading(form, 'Saving…');
    postAjax(window.location.pathname, new FormData(form)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

// ---------- Delivery zones ----------
let zonesData = <?= json_encode($zones) ?>;

const zoneForm = document.getElementById('zoneForm');
const zoneModalEl = document.getElementById('zoneModal');
const zoneModal = new bootstrap.Modal(zoneModalEl);
const feeTypeSelect = document.getElementById('zoneFormFeeType');
const feeInput = document.getElementById('zoneFormFee');

function syncFeeTypeUI() {
    const negotiable = feeTypeSelect.value === 'negotiable';
    document.getElementById('feeLabel').textContent = negotiable ? 'Starting fee (optional, RWF)' : 'Delivery fee (RWF)';
    document.getElementById('feeHelp').textContent = negotiable ? 'Customers will see this zone as "Negotiable" and agree the final fee with you directly.' : '';
    feeInput.required = !negotiable;
    if (negotiable && !feeInput.value) feeInput.value = 0;
}

feeTypeSelect.addEventListener('change', syncFeeTypeUI);

function resetZoneForm() {
    zoneForm.reset();
    document.getElementById('zoneFormAction').value = 'add';
    document.getElementById('zoneFormId').value = '';
    document.getElementById('zoneFormTitle').textContent = 'Add Delivery Zone';
    document.getElementById('zoneFormSubmitBtn').textContent = 'Add';
    syncFeeTypeUI();
}

document.getElementById('openAddZoneModalBtn').addEventListener('click', resetZoneForm);
zoneModalEl.addEventListener('hidden.bs.modal', resetZoneForm);

document.getElementById('zoneRows').addEventListener('click', function (e) {
    const editBtn = e.target.closest('.zone-edit-btn');
    const deleteBtn = e.target.closest('.zone-delete-btn');

    if (editBtn) {
        const zone = zonesData.find(z => z.id == editBtn.dataset.id);
        if (!zone) return;
        document.getElementById('zoneFormAction').value = 'edit';
        document.getElementById('zoneFormId').value = zone.id;
        document.getElementById('zoneFormName').value = zone.name;
        document.getElementById('zoneFormFee').value = zone.fee;
        feeTypeSelect.value = zone.fee_type;
        document.getElementById('zoneFormStatus').value = zone.status;
        document.getElementById('zoneFormTitle').textContent = 'Edit Delivery Zone';
        document.getElementById('zoneFormSubmitBtn').textContent = 'Update';
        syncFeeTypeUI();
        zoneModal.show();
    }

    if (deleteBtn) {
        if (!confirm('Delete this delivery zone?')) return;
        const fd = new FormData();
        fd.append('section', 'zone');
        fd.append('action', 'delete');
        fd.append('id', deleteBtn.dataset.id);
        postAjax(window.location.pathname, fd).then(res => {
            document.getElementById('zoneRows').innerHTML = res.rows;
            zonesData = res.zones;
            showToast(res.success ? 'success' : 'danger', res.message);
            applyZoneFilter();
        }).catch(() => showToast('danger', 'Network error. Please try again.'));
    }
});

zoneForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(zoneForm, document.getElementById('zoneFormAction').value === 'edit' ? 'Updating…' : 'Adding…');
    postAjax(window.location.pathname, new FormData(zoneForm)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            document.getElementById('zoneRows').innerHTML = res.rows;
            zonesData = res.zones;
            applyZoneFilter();
            zoneModal.hide();
        }
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

const zoneSearch = document.getElementById('zoneSearch');
const zoneStatusFilter = document.getElementById('zoneStatusFilter');

function applyZoneFilter() {
    const q = zoneSearch.value.trim().toLowerCase();
    const status = zoneStatusFilter.value;
    document.querySelectorAll('#zoneRows tr[data-name]').forEach(function (row) {
        const matchesName = row.dataset.name.indexOf(q) !== -1;
        const matchesStatus = !status || row.dataset.status === status;
        row.style.display = matchesName && matchesStatus ? '' : 'none';
    });
}

zoneSearch.addEventListener('input', applyZoneFilter);
zoneStatusFilter.addEventListener('change', applyZoneFilter);

// ---------- Payment methods ----------
let methodsData = <?= json_encode($methods) ?>;

const methodForm = document.getElementById('methodForm');
const methodModalEl = document.getElementById('methodModal');
const methodModal = new bootstrap.Modal(methodModalEl);

function resetMethodForm() {
    methodForm.reset();
    document.getElementById('methodFormAction').value = 'add';
    document.getElementById('methodFormId').value = '';
    document.getElementById('methodFormTitle').textContent = 'Add Payment Method';
    document.getElementById('methodFormSubmitBtn').textContent = 'Add';
}

document.getElementById('openAddMethodModalBtn').addEventListener('click', resetMethodForm);
methodModalEl.addEventListener('hidden.bs.modal', resetMethodForm);

document.getElementById('methodRows').addEventListener('click', function (e) {
    const editBtn = e.target.closest('.method-edit-btn');
    const deleteBtn = e.target.closest('.method-delete-btn');

    if (editBtn) {
        const method = methodsData.find(m => m.id == editBtn.dataset.id);
        if (!method) return;
        document.getElementById('methodFormAction').value = 'edit';
        document.getElementById('methodFormId').value = method.id;
        document.getElementById('methodFormName').value = method.name;
        document.getElementById('methodFormStatus').value = method.status;
        document.getElementById('methodFormRequiresProof').checked = !!Number(method.requires_proof);
        document.getElementById('methodFormInstructions').value = method.instructions || '';
        document.getElementById('methodFormTitle').textContent = 'Edit Payment Method';
        document.getElementById('methodFormSubmitBtn').textContent = 'Update';
        methodModal.show();
    }

    if (deleteBtn) {
        if (!confirm('Delete this payment method?')) return;
        const fd = new FormData();
        fd.append('section', 'payment_method');
        fd.append('action', 'delete');
        fd.append('id', deleteBtn.dataset.id);
        postAjax(window.location.pathname, fd).then(res => {
            document.getElementById('methodRows').innerHTML = res.rows;
            methodsData = res.methods;
            showToast(res.success ? 'success' : 'danger', res.message);
            applyMethodFilter();
        }).catch(() => showToast('danger', 'Network error. Please try again.'));
    }
});

methodForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const restore = setFormLoading(methodForm, document.getElementById('methodFormAction').value === 'edit' ? 'Updating…' : 'Adding…');
    postAjax(window.location.pathname, new FormData(methodForm)).then(res => {
        restore();
        showToast(res.success ? 'success' : 'danger', res.message);
        if (res.success) {
            document.getElementById('methodRows').innerHTML = res.rows;
            methodsData = res.methods;
            applyMethodFilter();
            methodModal.hide();
        }
    }).catch(() => {
        restore();
        showToast('danger', 'Network error. Please try again.');
    });
});

const methodSearch = document.getElementById('methodSearch');
const methodStatusFilter = document.getElementById('methodStatusFilter');

function applyMethodFilter() {
    const q = methodSearch.value.trim().toLowerCase();
    const status = methodStatusFilter.value;
    document.querySelectorAll('#methodRows tr[data-name]').forEach(function (row) {
        const matchesName = row.dataset.name.indexOf(q) !== -1;
        const matchesStatus = !status || row.dataset.status === status;
        row.style.display = matchesName && matchesStatus ? '' : 'none';
    });
}

methodSearch.addEventListener('input', applyMethodFilter);
methodStatusFilter.addEventListener('change', applyMethodFilter);

// Keep the URL's ?tab= in sync so a reload/redirect lands back on the right pane
document.querySelectorAll('#settingsTabs button').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () {
        const map = { '#tab-general': 'general', '#tab-zones': 'zones', '#tab-payment-methods': 'payment-methods' };
        const tab = map[this.dataset.bsTarget];
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
