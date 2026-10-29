<?php

use App\Http\Controllers\AiSetupController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DebtReconciliationController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\KnowledgeDocumentController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupplierDebtConfirmationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StoreController::class, 'home'])->name('home');
Route::get('/products', [StoreController::class, 'catalog'])->name('catalog');
Route::get('/products/{id}', [StoreController::class, 'product'])->name('product');
Route::get('/cart', [StoreController::class, 'cart'])->block(30, 30)->name('cart');
Route::get('/estimate', [EstimateController::class, 'index'])->name('estimate');
Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant');
Route::post('/assistant', [AssistantController::class, 'ask'])->middleware('throttle:10,1')->name('assistant.ask');
Route::get('/assistant/sources/{id}', [KnowledgeDocumentController::class, 'source'])->whereUuid('id')->name('assistant.source');
Route::post('/cart/add', [StoreController::class, 'addToCart'])->block(30, 30)->name('cart.add');
Route::post('/cart/update', [StoreController::class, 'updateCart'])->block(30, 30)->name('cart.update');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register/send-otp', [AuthController::class, 'sendRegistrationOtp'])->middleware('throttle:3,1')->name('register.otp.send');
    Route::post('/register', [AuthController::class, 'register'])->name('register.submit');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'permission:customer.portal'])->group(function () {
    Route::post('/quotes', [StoreController::class, 'requestQuote'])->block(30, 30)->name('quotes.request');
    Route::post('/pickup-orders', [StoreController::class, 'placePickupOrder'])->block(30, 30)->name('pickup-orders.store');
    Route::get('/my/quotes', [StoreController::class, 'myQuotes'])->name('my.quotes');
    Route::get('/my/quotes/{id}', [StoreController::class, 'showQuote'])->name('my.quote');
    Route::put('/my/quotes/{id}', [StoreController::class, 'updateQuote'])->name('my.quote.update');
    Route::post('/my/quotes/{id}/withdraw', [StoreController::class, 'withdrawQuote'])->name('my.quote.withdraw');
    Route::post('/my/quotes/{id}/accept', [StoreController::class, 'acceptQuote'])->name('my.quote.accept');
    Route::get('/my/contracts', [StoreController::class, 'myContracts'])->name('my.contracts');
    Route::post('/my/contracts/{id}/respond', [StoreController::class, 'respondToContract'])->name('my.contracts.respond');
    Route::get('/my/orders', [StoreController::class, 'myOrders'])->name('my.orders');
    Route::post('/my/orders/{id}/cancel', [StoreController::class, 'cancelPickupOrder'])->name('my.orders.cancel');
    Route::put('/my/orders/{id}', [StoreController::class, 'updatePickupOrder'])->name('my.orders.update');
    Route::get('/my/invoices/{id}', [StoreController::class, 'showInvoice'])->name('my.invoices.show');
    Route::post('/my/orders/{id}/returns', [StoreController::class, 'requestReturn'])->name('my.orders.returns');
    Route::get('/my/profile', [StoreController::class, 'profile'])->name('profile');
    Route::put('/my/profile', [StoreController::class, 'updateProfile'])->name('profile.update');
    Route::get('/my/debt-reconciliations', [DebtReconciliationController::class, 'customerIndex'])->name('my.debt-reconciliations.index');
    Route::get('/my/debt-reconciliations/{id}', [DebtReconciliationController::class, 'customerShow'])->name('my.debt-reconciliations.show');
    Route::post('/my/debt-reconciliations/{id}/respond', [DebtReconciliationController::class, 'respond'])->name('my.debt-reconciliations.respond');
});
Route::middleware(['auth', 'permission:staff.access'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/', [StaffController::class, 'dashboard'])->name('dashboard');
    Route::get('/work/{section}', [StaffController::class, 'section'])->name('section');
});

Route::middleware(['auth', 'permission:orders.review'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/orders/{id}/review', [StaffController::class, 'reviewOrder'])->name('orders.review');
});
Route::middleware(['auth', 'permission:quotes.send'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/quotes/{id}/send', [StaffController::class, 'sendQuote'])->name('quotes.send');
    Route::post('/quotes/{id}/reject', [StaffController::class, 'rejectQuote'])->name('quotes.reject');
});
Route::middleware(['auth', 'permission:contracts.create'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/orders/{orderId}/contracts', [StaffController::class, 'createContract'])->name('contracts.create');
    Route::put('/contracts/{id}', [StaffController::class, 'updateContract'])->name('contracts.update');
    Route::post('/contracts/{id}/submit', [StaffController::class, 'submitContract'])->name('contracts.submit');
    Route::post('/contracts/{id}/sign', [StaffController::class, 'signContract'])->name('contracts.sign');
});
Route::middleware(['auth', 'permission:contracts.review'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/contracts/{id}/review', [StaffController::class, 'reviewContract'])->name('contracts.review');
});
Route::middleware(['auth', 'permission:contracts.cancel'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/contracts/{id}/cancel', [StaffController::class, 'cancelContract'])->name('contracts.cancel');
});
Route::middleware(['auth', 'permission:quotes.discount.review'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/quotes/{id}/discount-review', [StaffController::class, 'reviewQuoteDiscount'])->name('quotes.discount-review');
});
Route::middleware(['auth', 'permission:purchases.approve'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/purchases/{id}/approve', [StaffController::class, 'approvePurchase'])->name('purchases.approve');
});
Route::middleware(['auth', 'permission:stocktakes.review'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/stocktakes/{id}/review', [StaffController::class, 'reviewStocktake'])->name('stocktakes.review');
});
Route::middleware(['auth', 'permission:payments.record'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/debts/{debtId}/payments', [StaffController::class, 'recordPayment'])->name('payments.record');
});
Route::middleware(['auth', 'permission:debts.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/debts/{id}/schedule', [StaffController::class, 'scheduleDebt'])->name('debts.schedule');
    Route::get('/debt-reconciliations', [DebtReconciliationController::class, 'staffIndex'])->name('debt-reconciliations.index');
    Route::post('/debt-reconciliations', [DebtReconciliationController::class, 'createSnapshot'])->name('debt-reconciliations.create');
    Route::get('/debt-reconciliations/{id}', [DebtReconciliationController::class, 'staffShow'])->name('debt-reconciliations.show');
    Route::post('/debt-reconciliations/{id}/send', [DebtReconciliationController::class, 'send'])->name('debt-reconciliations.send');
    Route::post('/debt-reconciliations/{id}/resolve', [DebtReconciliationController::class, 'resolve'])->name('debt-reconciliations.resolve');
});
Route::middleware(['auth', 'permission:supplier.confirmations.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/supplier-debt-confirmations', [SupplierDebtConfirmationController::class, 'index'])->name('supplier-debt-confirmations.index');
    Route::post('/supplier-debts/{debtId}/confirmations', [SupplierDebtConfirmationController::class, 'store'])->name('supplier-debts.confirmations.store');
    Route::get('/supplier-debt-confirmations/{id}/attachment', [SupplierDebtConfirmationController::class, 'attachment'])->name('supplier-debt-confirmations.attachment');
});
Route::middleware(['auth', 'permission:credits.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/customer-credits/{id}/use', [StaffController::class, 'useCustomerCredit'])->name('customer-credits.use');
});
Route::middleware(['auth', 'permission:supplier.debts.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/supplier-returns/{id}/settle', [StaffController::class, 'settleSupplierReturn'])->name('supplier-returns.settle');
});
Route::middleware(['auth', 'permission:invoices.issue'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/orders/{id}/invoice', [StaffController::class, 'issueInvoice'])->name('orders.invoice');
});
Route::middleware(['auth', 'permission:returns.settle'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/returns/{id}/settle', [StaffController::class, 'settleReturn'])->name('returns.settle');
});
Route::get('/staff/approval-history', [StaffController::class, 'approvalHistory'])->middleware('auth')->name('staff.approval-history');
Route::middleware(['auth', 'permission:purchases.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/purchases', [StaffController::class, 'createPurchase'])->name('purchases.create');
    Route::post('/purchases/{id}/receive', [StaffController::class, 'receivePurchase'])->name('purchases.receive');
});
Route::middleware(['auth', 'permission:suppliers.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/purchases/{id}/incidents', [StaffController::class, 'reportSupplierIncident'])->name('purchases.incidents.create');
    Route::post('/supplier-incidents/{id}/resolve', [StaffController::class, 'resolveSupplierIncident'])->name('supplier-incidents.resolve');
});
Route::middleware(['auth', 'permission:inventory.issue'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/orders/{id}/issue', [StaffController::class, 'issueOrder'])->name('orders.issue');
});
Route::middleware(['auth', 'permission:stocktakes.create'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/stocktakes', [StaffController::class, 'proposeStocktake'])->name('stocktakes.create');
});
Route::middleware(['auth', 'permission:warehouse.fulfill'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/orders/{id}/ship', [StaffController::class, 'planShipment'])->name('orders.ship');
    Route::post('/orders/{id}/pickup', [StaffController::class, 'handoverPickup'])->name('orders.pickup');
});
Route::middleware(['auth', 'permission:delivery-runs.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/delivery-runs', [StaffController::class, 'groupDeliveryRun'])->name('delivery-runs.create');
});
Route::middleware(['auth', 'permission:returns.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/returns/{id}/inspect', [StaffController::class, 'inspectReturn'])->name('returns.inspect');
    Route::post('/returns/{id}/supplier-return', [StaffController::class, 'sendDamagedReturnToSupplier'])->name('returns.supplier-return');
    Route::post('/returns/{id}/exchange', [StaffController::class, 'issueExchange'])->name('returns.exchange');
    Route::post('/returns/{id}/restock', [StaffController::class, 'requestExchangeRestock'])->name('returns.restock');
});
Route::middleware(['auth', 'permission:shipments.manage'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/shipments/{id}/pickup', [StaffController::class, 'pickupShipment'])->name('shipments.pickup');
    Route::post('/shipments/{id}/reschedule', [StaffController::class, 'rescheduleShipment'])->name('shipments.reschedule');
    Route::post('/shipments/{id}/handover', [StaffController::class, 'handover'])->name('shipments.handover');
});
Route::middleware(['auth', 'permission:returns.collect'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/returns/{id}/collect', [StaffController::class, 'collectReturn'])->name('returns.collect');
});

Route::middleware(['auth', 'permission:management.view'])->prefix('management')->name('management.')->group(function () {
    Route::get('/', [ManagementController::class, 'index'])->name('index');
});
Route::middleware(['auth', 'permission:catalog.propose'])->prefix('management')->name('management.')->group(function () {
    Route::post('/products/propose', [ManagementController::class, 'proposeProduct'])->name('products.propose');
    Route::post('/categories/propose', [ManagementController::class, 'proposeCategory'])->name('categories.propose');
});
Route::middleware(['auth', 'permission:catalog.review'])->prefix('management')->name('management.')->group(function () {
    Route::post('/changes/{id}/review', [ManagementController::class, 'reviewChange'])->name('changes.review');
});
Route::middleware(['auth', 'permission:customers.credit.manage'])->prefix('management')->name('management.')->group(function () {
    Route::post('/customers/{customerId}/credit-limit', [ManagementController::class, 'creditLimit'])->name('customers.credit-limit');
});
Route::middleware(['auth', 'permission:suppliers.manage'])->prefix('management')->name('management.')->group(function () {
    Route::post('/suppliers', [ManagementController::class, 'supplier'])->name('suppliers.save');
});
Route::middleware(['auth', 'permission:warehouses.manage'])->prefix('management')->name('management.')->group(function () {
    Route::post('/warehouses', [ManagementController::class, 'warehouse'])->name('warehouses.save');
});
Route::middleware(['auth', 'permission:backups.manage'])->prefix('management')->name('management.')->group(function () {
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/backups', [BackupController::class, 'store'])->middleware('throttle:5,1')->name('backups.store');
    Route::post('/backups/upload', [BackupController::class, 'upload'])->middleware('throttle:5,1')->name('backups.upload');
    Route::get('/backups/{id}', [BackupController::class, 'show'])->whereUuid('id')->name('backups.show');
    Route::get('/backups/{id}/download', [BackupController::class, 'download'])->whereUuid('id')->name('backups.download');
    Route::post('/backups/{id}/restore', [BackupController::class, 'restore'])->whereUuid('id')->middleware('throttle:3,1')->name('backups.restore');
});
Route::middleware(['auth', 'permission:ai.manage'])->prefix('management')->name('management.')->group(function () {
    Route::get('/ai/setup', [AiSetupController::class, 'index'])->name('ai-setup');
    Route::put('/ai/setup', [AiSetupController::class, 'update'])->middleware('throttle:10,1')->name('ai-setup.update');
});
Route::middleware(['auth', 'permission:knowledge.manage'])->prefix('management')->name('management.')->group(function () {
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('knowledge');
    Route::put('/knowledge', [KnowledgeBaseController::class, 'update'])->name('knowledge.update');
    Route::get('/knowledge/documents', [KnowledgeDocumentController::class, 'index'])->name('documents.index');
    Route::post('/knowledge/documents', [KnowledgeDocumentController::class, 'store'])->name('documents.store');
    Route::post('/knowledge/documents/search', [KnowledgeDocumentController::class, 'search'])->name('documents.search');
    Route::get('/knowledge/documents/{id}', [KnowledgeDocumentController::class, 'show'])->whereUuid('id')->name('documents.show');
    Route::put('/knowledge/documents/{id}', [KnowledgeDocumentController::class, 'update'])->whereUuid('id')->name('documents.update');
    Route::patch('/knowledge/documents/{id}/toggle', [KnowledgeDocumentController::class, 'toggle'])->whereUuid('id')->name('documents.toggle');
    Route::delete('/knowledge/documents/{id}', [KnowledgeDocumentController::class, 'destroy'])->whereUuid('id')->name('documents.destroy');
});
Route::middleware(['auth', 'permission:users.manage'])->prefix('management')->name('management.')->group(function () {
    Route::post('/users', [ManagementController::class, 'createUser'])->name('users.create');
    Route::put('/users/{id}', [ManagementController::class, 'updateUser'])->name('users.update');
    Route::post('/users/{id}/reset-password', [ManagementController::class, 'resetPassword'])->name('users.reset');
    Route::post('/users/{id}/unlock', [ManagementController::class, 'unlockUser'])->name('users.unlock');
});

Route::middleware(['auth', 'permission:reports.view'])->prefix('staff/reports')->name('staff.reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/{id}/export', [ReportController::class, 'export'])->name('export');
    Route::get('/{id}/pdf', [ReportController::class, 'downloadPdf'])->name('pdf');
    Route::get('/{id}/print', [ReportController::class, 'print'])->name('print');
});
Route::post('/staff/reports', [ReportController::class, 'create'])->middleware(['auth', 'permission:reports.create'])->name('staff.reports.create');
Route::post('/staff/reports/{id}/review', [ReportController::class, 'review'])->middleware(['auth', 'permission:reports.review'])->name('staff.reports.review');
