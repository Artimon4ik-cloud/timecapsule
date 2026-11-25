<?php
// GET /notifications - displays notifications created by Lambda Mailer and Lambda Opener.
function notifications_index(): void
{
    $user = require_user();

    $notifications = db_query(
        'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100',
        [(int) $user['id']]
    )->fetchAll();

    view('notifications', [
        'title' => 'Notifications',
        'nav' => 'notifications',
        'notifications' => $notifications,
    ]);
}
