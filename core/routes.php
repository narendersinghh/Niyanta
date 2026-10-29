<?php
/** Core (non-module) routes. Module routes are declared in each module.php. */
use Core\Controllers\{AuthController, DashboardController, AccountController, FileController, SearchController, NotificationController, CronController};

$r = Core\App::router();
$r->get('/login', [AuthController::class, 'showLogin'], ['auth' => false]);
$r->post('/login', [AuthController::class, 'login'], ['auth' => false]);
$r->post('/logout', [AuthController::class, 'logout']);
$r->get('/forgot-password', [AuthController::class, 'showForgot'], ['auth' => false]);
$r->post('/forgot-password', [AuthController::class, 'forgot'], ['auth' => false, 'throttle' => [5, 900]]);
$r->get('/reset-password/{token}', [AuthController::class, 'showReset'], ['auth' => false]);
$r->post('/reset-password', [AuthController::class, 'reset'], ['auth' => false, 'throttle' => [10, 900]]);

$r->get('/', [DashboardController::class, 'index']);
$r->get('/search', [SearchController::class, 'index']);
$r->get('/account', [AccountController::class, 'edit']);
$r->post('/account', [AccountController::class, 'update']);
$r->post('/account/password', [AccountController::class, 'password']);
$r->post('/account/theme', [AccountController::class, 'theme']);
$r->post('/account/avatar', [AccountController::class, 'avatar']);
$r->get('/avatar/{file}', [AccountController::class, 'showAvatar']);
$r->get('/notifications', [NotificationController::class, 'index']);
$r->get('/notifications/feed', [NotificationController::class, 'feed']);
$r->post('/notifications/read', [NotificationController::class, 'markRead']);
$r->get('/files/{id}', [FileController::class, 'download']);
$r->get('/files/{id}/view', [FileController::class, 'inline']);
$r->post('/files/{id}/delete', [FileController::class, 'delete']);
$r->post('/errors/client', [Core\Controllers\ErrorController::class, 'client'], ['auth' => false, 'csrf' => false, 'throttle' => [20, 60]]);
$r->post('/cron/tick', [CronController::class, 'tick'], ['auth' => false, 'csrf' => false, 'throttle' => [4, 60]]);
$r->any('/cron/run/{token}', [CronController::class, 'web'], ['auth' => false, 'csrf' => false, 'throttle' => [6, 60]]);
