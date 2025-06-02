<?php

namespace OCA\AlbumNotifications\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class DailyNotificationJob extends TimedJob {
    private $config;
    private $db;
    private $userManager;
    private $mailer;
    private $urlGenerator;
    private $appManager;
    private $logger;

    public function __construct(
        ITimeFactory $time,
        IConfig $config,
        IDBConnection $db,
        IUserManager $userManager,
        IMailer $mailer,
        IURLGenerator $urlGenerator,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->config = $config;
        $this->db = $db;
        $this->userManager = $userManager;
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->appManager = $appManager;
        $this->logger = $logger;

        // Set to run daily at 7:30 PM CST (00:30 UTC next day, accounting for CST = UTC-6)
        // Note: You may need to adjust this based on your server's timezone
        $this->setInterval(24 * 60 * 60); // 24 hours
    }

    protected function run($argument) {
        $this->logger->info('Starting daily album notification job', ['app' => 'album_notifications']);

        // Get current time in CST
        $now = new \DateTime('now', new \DateTimeZone('America/Chicago'));
        $currentHour = (int)$now->format('H');
        $currentMinute = (int)$now->format('i');

        // Only run between 7:30 PM and 7:35 PM CST to ensure it runs once per day
        if ($currentHour !== 19 || $currentMinute < 30 || $currentMinute > 35) {
            $this->logger->debug('Skipping notification job - not the right time. Current time: ' . $now->format('H:i'), ['app' => 'album_notifications']);
            return;
        }

        // Get 24 hours ago timestamp
        $yesterday = $now->sub(new \DateInterval('P1D'));
        $yesterdayTimestamp = $yesterday->getTimestamp();

        $this->logger->info('Checking for photos added since: ' . $yesterday->format('Y-m-d H:i:s'), ['app' => 'album_notifications']);

        // Get all users who have album notifications configured
        $users = $this->getUsersWithNotifications();

        foreach ($users as $userId) {
            try {
                $this->processUserNotifications($userId, $yesterdayTimestamp);
            } catch (\Exception $e) {
                $this->logger->error('Failed to process notifications for user ' . $userId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
            }
        }

        $this->logger->info('Daily album notification job completed', ['app' => 'album_notifications']);
    }

    private function getUsersWithNotifications(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('userid')
           ->from('preferences')
           ->where($qb->expr()->eq('appid', $qb->createNamedParameter('album_notifications')))
           ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('selected_albums')))
           ->andWhere($qb->expr()->neq('configvalue', $qb->createNamedParameter('[]')));

        $result = $qb->executeQuery();
        $users = [];
        while ($row = $result->fetch()) {
            $users[] = $row['userid'];
        }
        $result->closeCursor();

        return $users;
    }

    private function processUserNotifications(string $userId, int $yesterdayTimestamp) {
        $user = $this->userManager->get($userId);
        if (!$user || !$user->getEMailAddress()) {
            $this->logger->debug('Skipping user ' . $userId . ' - no email address', ['app' => 'album_notifications']);
            return;
        }

        // Get user's selected albums
        $selectedAlbumsJson = $this->config->getUserValue($userId, 'album_notifications', 'selected_albums', '[]');
        $selectedAlbums = json_decode($selectedAlbumsJson, true) ?? [];

        if (empty($selectedAlbums)) {
            return;
        }

        $this->logger->debug('Processing notifications for user ' . $userId . ' with ' . count($selectedAlbums) . ' selected albums', ['app' => 'album_notifications']);

        // Check for updates in selected albums
        $updatedAlbums = [];

        foreach ($selectedAlbums as $albumId) {
            $albumInfo = $this->checkAlbumForUpdates($albumId, $yesterdayTimestamp, $userId);
            if ($albumInfo) {
                $updatedAlbums[] = $albumInfo;
            }
        }

        // Send notification email if there are updates
        if (!empty($updatedAlbums)) {
            $this->sendNotificationEmail($user, $updatedAlbums);
        }
    }

    private function checkAlbumForUpdates(string $albumId, int $yesterdayTimestamp, string $userId): ?array {
        // Parse album ID to determine source
        if (strpos($albumId, 'photos_') === 0) {
            return $this->checkPhotosAlbumForUpdates($albumId, $yesterdayTimestamp, $userId);
        } elseif (strpos($albumId, 'memories_') === 0) {
            return $this->checkMemoriesAlbumForUpdates($albumId, $yesterdayTimestamp, $userId);
        }

        return null;
    }

    private function checkPhotosAlbumForUpdates(string $albumId, int $yesterdayTimestamp, string $userId): ?array {
        if (!$this->appManager->isEnabledForUser('photos') || !$this->tableExists('photos_albums')) {
            return null;
        }

        $realAlbumId = str_replace('photos_', '', $albumId);

        try {
            // First get album info
            $qb = $this->db->getQueryBuilder();
            $qb->select('name', 'last_added_photo')
               ->from('photos_albums')
               ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($realAlbumId)))
               ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
            $albumData = $result->fetch();
            $result->closeCursor();

            if (!$albumData) {
                return null;
            }

            // Check if last_added_photo is within the last 24 hours
            $lastAddedPhoto = $albumData['last_added_photo'];
            if ($lastAddedPhoto && $lastAddedPhoto >= $yesterdayTimestamp) {
                // Count new photos added
                $photoCount = $this->countNewPhotosInAlbum('photos', $realAlbumId, $yesterdayTimestamp, $userId);
                
                return [
                    'name' => $albumData['name'] ?: 'Unnamed Album',
                    'source' => 'Photos',
                    'photo_count' => $photoCount
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error checking Photos album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return null;
    }

    private function checkMemoriesAlbumForUpdates(string $albumId, int $yesterdayTimestamp, string $userId): ?array {
        if (!$this->appManager->isEnabledForUser('memories')) {
            return null;
        }

        $realAlbumId = str_replace('memories_', '', $albumId);

        try {
            // Try common table names for Memories
            $possibleTables = ['memories_albums', 'oc_memories_albums'];
            
            foreach ($possibleTables as $tableName) {
                if ($this->tableExists($tableName)) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('name', 'last_added_photo')
                       ->from($tableName)
                       ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($realAlbumId)))
                       ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

                    $result = $qb->executeQuery();
                    $albumData = $result->fetch();
                    $result->closeCursor();

                    if ($albumData) {
                        // Check if last_added_photo is within the last 24 hours
                        $lastAddedPhoto = $albumData['last_added_photo'];
                        if ($lastAddedPhoto && $lastAddedPhoto >= $yesterdayTimestamp) {
                            // Count new photos added
                            $photoCount = $this->countNewPhotosInAlbum('memories', $realAlbumId, $yesterdayTimestamp, $userId);
                            
                            return [
                                'name' => $albumData['name'] ?: 'Unnamed Album',
                                'source' => 'Memories',
                                'photo_count' => $photoCount
                            ];
                        }
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error checking Memories album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return null;
    }

    private function countNewPhotosInAlbum(string $source, string $albumId, int $yesterdayTimestamp, string $userId): int {
        try {
            if ($source === 'photos' && $this->tableExists('photos_albums_files')) {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*)'))
                   ->from('photos_albums_files')
                   ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId)))
                   ->andWhere($qb->expr()->gte('added_at', $qb->createNamedParameter($yesterdayTimestamp)));

                $result = $qb->executeQuery();
                $count = $result->fetchOne();
                $result->closeCursor();
                
                return (int)$count;
            } elseif ($source === 'memories') {
                // Try to count from memories album files table
                $possibleTables = ['memories_albums_files', 'oc_memories_albums_files'];
                
                foreach ($possibleTables as $tableName) {
                    if ($this->tableExists($tableName)) {
                        $qb = $this->db->getQueryBuilder();
                        $qb->select($qb->createFunction('COUNT(*)'))
                           ->from($tableName)
                           ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId)))
                           ->andWhere($qb->expr()->gte('added_at', $qb->createNamedParameter($yesterdayTimestamp)));

                        $result = $qb->executeQuery();
                        $count = $result->fetchOne();
                        $result->closeCursor();
                        
                        return (int)$count;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error counting new photos in ' . $source . ' album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return 1; // Default to 1 if we can't count but know there are updates
    }

    private function sendNotificationEmail(IUser $user, array $updatedAlbums) {
        try {
            $email = $user->getEMailAddress();
            $displayName = $user->getDisplayName();
            $instanceName = $this->config->getSystemValue('instanceid', 'Nextcloud');

            $message = $this->mailer->createMessage();
            $message->setTo([$email => $displayName]);
            $message->setSubject('Daily Album Update - New Photos Added');
            
            $htmlBody = $this->generateNotificationEmailHtml($displayName, $instanceName, $updatedAlbums);
            $message->setHtmlBody($htmlBody);

            $this->mailer->send($message);

            $this->logger->info('Daily notification email sent to: ' . $email . ' for ' . count($updatedAlbums) . ' albums', ['app' => 'album_notifications']);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send daily notification email to ' . $user->getUID() . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }
    }

    private function generateNotificationEmailHtml(string $displayName, string $instanceName, array $updatedAlbums): string {
        $logoUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'logo/logo.png'));
        $photosUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('photos.Page.index'));
        
        $albumsHtml = '';
        $totalPhotos = 0;
        
        foreach ($updatedAlbums as $album) {
            $photoCount = $album['photo_count'];
            $totalPhotos += $photoCount;
            $photoText = $photoCount === 1 ? '1 new photo' : $photoCount . ' new photos';
            
            $albumsHtml .= '
                <div style="background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #0082c9;">
                    <h3 style="margin: 0 0 5px 0; color: #333;">' . htmlspecialchars($album['name']) . '</h3>
                    <p style="margin: 0; color: #666; font-size: 14px;">
                        <strong>' . $photoText . '</strong> added from ' . htmlspecialchars($album['source']) . '
                    </p>
                </div>';
        }
        
        $summaryText = count($updatedAlbums) === 1 ? 
            'You have new photos in 1 album' : 
            'You have new photos in ' . count($updatedAlbums) . ' albums';
        
        $totalPhotoText = $totalPhotos === 1 ? '1 new photo total' : $totalPhotos . ' new photos total';

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Daily Album Update</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; background: #0082c9; color: white; padding: 20px; border-radius: 5px; }
                .logo { max-width: 150px; height: auto; }
                .content { background: #fafafa; padding: 25px; border-radius: 5px; margin: 20px 0; }
                .summary { background: #e8f4fd; padding: 20px; border-radius: 5px; margin: 20px 0; text-align: center; }
                .cta-button { 
                    display: inline-block; 
                    background: #0082c9; 
                    color: white; 
                    padding: 12px 25px; 
                    text-decoration: none; 
                    border-radius: 5px; 
                    margin: 20px 0; 
                    font-weight: bold;
                }
                .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1 style="margin: 0;">📸 Daily Album Update</h1>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">New photos have been added to your albums</p>
                </div>
                
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($displayName) . '!</h2>
                    <p>' . $summaryText . ' in the past 24 hours.</p>
                    
                    ' . $albumsHtml . '
                    
                    <div class="summary">
                        <h3 style="margin: 0 0 10px 0; color: #0082c9;">📊 Summary</h3>
                        <p style="margin: 0; font-size: 16px;"><strong>' . $totalPhotoText . '</strong></p>
                    </div>
                    
                    <div style="text-align: center;">
                        <a href="' . $photosUrl . '" class="cta-button">View Your Photos</a>
                    </div>
                    
                    <p style="font-size: 14px; color: #666; margin-top: 20px;">
                        💡 <strong>Tip:</strong> You can manage which albums send you notifications in your personal settings.
                    </p>
                </div>
                
                <div class="footer">
                    <p>This email was sent from your ' . htmlspecialchars($instanceName) . ' instance.</p>
                    <p>You received this because you have album notifications enabled for these albums.</p>
                </div>
            </div>
        </body>
        </html>';
    }

    private function tableExists(string $tableName): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'))
               ->from($tableName)
               ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}