<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Setup array mailer
config(['mail.default' => 'array']);
$arrayMailer = app('mail.manager')->mailer('array');

// 2. Create a test mailable using a view
file_put_contents(resource_path('views/test_cid.blade.php'), '<html><body><img src="{{ $message->embed(public_path(\'assets/email-logo.png\')) }}"></body></html>');

$mailable = new class extends \Illuminate\Mail\Mailable {
    public function build() {
        return $this->view('test_cid')->subject('Test CID Embed');
    }
};

$mailable->to('test@example.com')->send($arrayMailer);

$transport = $arrayMailer->getSymfonyTransport();
$sent = $transport->messages()->last();
$email = $sent->getOriginalMessage();

echo "HTML BODY:\n";
echo $email->getHtmlBody();
echo "\n\nATTACHMENTS:\n";
foreach ($email->getAttachments() as $attachment) {
    echo "Content-ID: " . $attachment->getContentId() . "\n";
    echo "Filename: " . $attachment->getFilename() . "\n";
}

unlink(resource_path('views/test_cid.blade.php'));
