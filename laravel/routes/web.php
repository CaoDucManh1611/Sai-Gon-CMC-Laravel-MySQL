<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StoreController::class, 'home'])->name('home');
Route::get('/products', [StoreController::class, 'catalog'])->name('catalog');
Route::get('/products/{id}', [StoreController::class, 'product'])->name('product');
Route::get('/cart', [StoreController::class, 'cart'])->name('cart');
Route::get('/estimate', [EstimateController::class, 'index'])->name('estimate');
Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant');
Route::post('/assistant', [AssistantController::class, 'ask'])->middleware('throttle:10,1')->name('assistant.ask');
Route::post('/cart/add', [StoreController::class, 'addToCart'])->name('cart.add');
Route::post('/cart/update', [StoreController::class, 'updateCart'])->name('cart.update');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.submit');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'role:customer'])->group(function () {
    Route::post('/quotes', [StoreController::class, 'requestQuote'])->name('quotes.request');
    Route::post('/pickup-orders', [StoreController::class, 'placePickupOrder'])->name('pickup-orders.store');
    Route::get('/my/quotes', [StoreController::class, 'myQuotes'])->name('my.quotes');
    Route::get('/my/quotes/{id}', [StoreController::class, 'showQuote'])->name('my.quote');
    Route::post('/my/quotes/{id}/accept', [StoreController::class, 'acceptQuote'])->name('my.quote.accept');
    Route::get('/my/contracts', [StoreController::class, 'myContracts'])->name('my.contracts');
    Route::post('/my/contracts/{id}/respond', [StoreController::class, 'respondToContract'])->name('my.contracts.respond');
    Route::get('/my/orders', [StoreController::class, 'myOrders'])->name('my.orders');
    Route::post('/my/orders/{id}/cancel', [StoreController::class, 'cancelPickupOrder'])->name('my.orders.cancel');
    Route::get('/my/invoices/{id}', [StoreController::class, 'showInvoice'])->name('my.invoices.show');
    Route::post('/my/orders/{id}/returns', [StoreController::class, 'requestReturn'])->name('my.orders.returns');
    Route::get('/my/profile', [StoreController::class, 'profile'])->name('profile');
    Route::put('/my/profile', [StoreController::class, 'updateProfile'])->name('profile.update');
});
Route::middleware(['auth', 'role:sales,warehouse,accounting,manager,admin,delivery'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/', [StaffController::class, 'dashboard'])->name('dashboard');
    Route::get('/work/{section}', [StaffController::class, 'section'])->name('section');
});
Route::middleware(['auth', 'role:sales,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/quotes/{id}/send', [StaffController::class, 'sendQuote'])->name('quotes.send');
    Route::post('/quotes/{id}/reject', [StaffController::class, 'rejectQuote'])->name('quotes.reject');
    Route::post('/quotes/{quoteId}/contracts', [StaffController::class, 'createContract'])->name('contracts.create');
    Route::put('/contracts/{id}', [StaffController::class, 'updateContract'])->name('contracts.update');
    Route::post('/contracts/{id}/submit', [StaffController::class, 'submitContract'])->name('contracts.submit');
    Route::post('/contracts/{id}/sign', [StaffController::class, 'signContract'])->name('contracts.sign');
});
Route::middleware(['auth', 'role:manager,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/contracts/{id}/review', [StaffController::class, 'reviewContract'])->name('contracts.review');
    Route::post('/contracts/{id}/cancel', [StaffController::class, 'cancelContract'])->name('contracts.cancel');
    Route::post('/quotes/{id}/discount-review', [StaffController::class, 'reviewQuoteDiscount'])->name('quotes.discount-review');
    Route::post('/purchases/{id}/approve', [StaffController::class, 'approvePurchase'])->name('purchases.approve');
    Route::post('/stocktakes/{id}/review', [StaffController::class, 'reviewStocktake'])->name('stocktakes.review');
});
Route::middleware(['auth', 'role:accounting,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/debts/{debtId}/payments', [StaffController::class, 'recordPayment'])->name('payments.record');
    Route::post('/debts/{id}/schedule', [StaffController::class, 'scheduleDebt'])->name('debts.schedule');
    Route::post('/customer-credits/{id}/use', [StaffController::class, 'useCustomerCredit'])->name('customer-credits.use');
    Route::post('/supplier-returns/{id}/settle', [StaffController::class, 'settleSupplierReturn'])->name('supplier-returns.settle');
    Route::post('/orders/{id}/invoice', [StaffController::class, 'issueInvoice'])->name('orders.invoice');
    Route::post('/returns/{id}/settle', [StaffController::class, 'settleReturn'])->name('returns.settle');
});
Route::middleware(['auth', 'role:warehouse,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/purchases', [StaffController::class, 'createPurchase'])->name('purchases.create');
    Route::post('/purchases/{id}/receive', [StaffController::class, 'receivePurchase'])->name('purchases.receive');
    Route::post('/purchases/{id}/incidents', [StaffController::class, 'reportSupplierIncident'])->name('purchases.incidents.create');
    Route::post('/supplier-incidents/{id}/resolve', [StaffController::class, 'resolveSupplierIncident'])->name('supplier-incidents.resolve');
    Route::post('/orders/{id}/issue', [StaffController::class, 'issueOrder'])->name('orders.issue');
    Route::post('/stocktakes', [StaffController::class, 'proposeStocktake'])->name('stocktakes.create');
    Route::post('/orders/{id}/ship', [StaffController::class, 'planShipment'])->name('orders.ship');
    Route::post('/orders/{id}/pickup', [StaffController::class, 'handoverPickup'])->name('orders.pickup');
    Route::post('/delivery-runs', [StaffController::class, 'groupDeliveryRun'])->name('delivery-runs.create');
    Route::post('/returns/{id}/inspect', [StaffController::class, 'inspectReturn'])->name('returns.inspect');
    Route::post('/returns/{id}/supplier-return', [StaffController::class, 'sendDamagedReturnToSupplier'])->name('returns.supplier-return');
    Route::post('/returns/{id}/exchange', [StaffController::class, 'issueExchange'])->name('returns.exchange');
});
Route::middleware(['auth', 'role:warehouse,delivery,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::post('/shipments/{id}/pickup', [StaffController::class, 'pickupShipment'])->name('shipments.pickup');
    Route::post('/shipments/{id}/reschedule', [StaffController::class, 'rescheduleShipment'])->name('shipments.reschedule');
    Route::post('/shipments/{id}/handover', [StaffController::class, 'handover'])->name('shipments.handover');
});

Route::middleware(['auth', 'role:sales,warehouse,manager,admin'])->prefix('management')->name('management.')->group(function () {
    Route::get('/', [ManagementController::class, 'index'])->name('index');
});
Route::middleware(['auth', 'role:sales,warehouse,admin'])->prefix('management')->name('management.')->group(function () {
    Route::post('/products/propose', [ManagementController::class, 'proposeProduct'])->name('products.propose');
    Route::post('/categories/propose', [ManagementController::class, 'proposeCategory'])->name('categories.propose');
});
Route::middleware(['auth', 'role:manager,admin'])->prefix('management')->name('management.')->group(function () {
    Route::post('/changes/{id}/review', [ManagementController::class, 'reviewChange'])->name('changes.review');
    Route::post('/customers/{customerId}/credit-limit', [ManagementController::class, 'creditLimit'])->name('customers.credit-limit');
});
Route::middleware(['auth', 'role:warehouse,admin'])->prefix('management')->name('management.')->group(function () {
    Route::post('/suppliers', [ManagementController::class, 'supplier'])->name('suppliers.save');
    Route::post('/warehouses', [ManagementController::class, 'warehouse'])->name('warehouses.save');
});
Route::middleware(['auth', 'role:admin'])->prefix('management')->name('management.')->group(function () {
    Route::post('/users', [ManagementController::class, 'createUser'])->name('users.create');
    Route::put('/users/{id}', [ManagementController::class, 'updateUser'])->name('users.update');
    Route::post('/users/{id}/reset-password', [ManagementController::class, 'resetPassword'])->name('users.reset');
});

Route::middleware(['auth', 'role:sales,warehouse,accounting,manager,admin,delivery'])->prefix('staff/reports')->name('staff.reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/{id}/export', [ReportController::class, 'export'])->name('export');
    Route::get('/{id}/print', [ReportController::class, 'print'])->name('print');
});
Route::post('/staff/reports', [ReportController::class, 'create'])->middleware(['auth', 'role:sales,warehouse,accounting,manager,admin,delivery'])->name('staff.reports.create');
Route::post('/staff/reports/{id}/review', [ReportController::class, 'review'])->middleware(['auth', 'role:manager,admin'])->name('staff.reports.review');
