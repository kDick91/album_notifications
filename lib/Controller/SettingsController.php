<?php

namespace OCA\AlbumNotifications\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\IUserManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {
    private $config;
    private $userSession;
    private $userId;
    private $mailer;
    private $userManager;
    private $urlGenerator;
    private $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IMailer $mailer,
        IUserManager $userManager,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->userId = $userSession->getUser()->getUID();
        $this->mailer = $mailer;
        $this->userManager = $userManager;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     */
    public function saveSettings(): JSONResponse {
        $selectedAlbums = $this->request->getParam('selected_albums');
        
        if ($selectedAlbums !== null) {
            // Validate JSON
            $decoded = json_decode($selectedAlbums, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->config->setUserValue(
                    $this->userId,
                    'album_notifications',
                    'selected_albums',
                    $selectedAlbums
                );
                return new JSONResponse(['status' => 'success']);
            }
        }
        
        return new JSONResponse(['status' => 'error', 'message' => 'Invalid data'], 400);
    }

    /**
     * @NoAdminRequired
     */
    public function sendTestEmail(): JSONResponse {
        try {
            $user = $this->userManager->get($this->userId);
            if (!$user) {
                return new JSONResponse(['status' => 'error', 'message' => 'User not found'], 404);
            }

            $email = $user->getEMailAddress();
            if (!$email) {
                return new JSONResponse(['status' => 'error', 'message' => 'No email address found in your profile. Please add an email address in your personal settings.'], 400);
            }

            $displayName = $user->getDisplayName();
            $instanceName = $this->config->getSystemValue('instanceid', 'Nextcloud');

            // Create the email
            $message = $this->mailer->createMessage();
            $message->setTo([$email => $displayName]);
            $message->setSubject('Album Notification Test Email');
            
            $htmlBody = $this->generateTestEmailHtml($displayName, $instanceName);
            
            // Set HTML body (this automatically creates a plain text version)
            $message->setHtmlBody($htmlBody);

            // Send the email
            $this->mailer->send($message);

            $this->logger->info('Test email sent successfully to: ' . $email, ['app' => 'album_notifications']);

            return new JSONResponse([
                'status' => 'success', 
                'message' => 'Test email sent successfully to ' . $email
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send test email: ' . $e->getMessage(), ['app' => 'album_notifications']);
            return new JSONResponse([
                'status' => 'error', 
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ], 500);
        }
    }

    private function generateTestEmailHtml(string $displayName, string $instanceName): string {
        $logoUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'logo/logo.png'));
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Album Notification Test</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .logo { max-width: 200px; height: auto; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <img src="' . $logoUrl . '" alt="Nextcloud" class="logo">
                    <h1>Album Notifications Test</h1>
                </div>
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($displayName) . '!</h2>
                    <p>This is a test email from your Album Notifications app.</p>
                    <p>If you received this email, it means:</p>
                    <ul>
                        <li>✅ Your email configuration is working correctly</li>
                        <li>✅ Album notifications will be delivered to this address</li>
                        <li>✅ You\'re all set to receive daily album updates</li>
                    </ul>
                    <p>You can now configure which albums you want to receive notifications for in your settings.</p>
                </div>
                <div class="footer">
                    <p>This email was sent from your ' . htmlspecialchars($instanceName) . ' instance.</p>
                </div>
            </div>
        </body>
        </html>';
    }
}