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

        // Set to run daily (24 hours)
        $this->setInterval(24 * 60 * 60);
    }

    protected function run($argument) {
        $this->logger->info('Starting daily album notification job', ['app' => 'album_notifications']);

        // Get 24 hours ago timestamp
        $now = new \DateTime('now');
        $yesterday = clone $now;
        $yesterday->sub(new \DateInterval('P1D'));
        $yesterdayTimestamp = $yesterday->getTimestamp();

        $this->logger->info('Checking for photos added since: ' . $yesterday->format('Y-m-d H:i:s'), ['app' => 'album_notifications']);

        // Get all users who have album notifications configured
        $users = $this->getUsersWithNotifications();
        $this->logger->info('Found ' . count($users) . ' users with notifications configured', ['app' => 'album_notifications']);

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
           ->andWhere($qb->expr()->neq('configvalue', $qb->createNamedParameter('[]')))
           ->andWhere($qb->expr()->neq('configvalue', $qb->createNamedParameter('')));

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
            $this->logger->debug('No selected albums for user ' . $userId, ['app' => 'album_notifications']);
            return;
        }

        $this->logger->debug('Processing notifications for user ' . $userId . ' with ' . count($selectedAlbums) . ' selected albums', ['app' => 'album_notifications']);

        // Check for updates in selected albums
        $updatedAlbums = [];

        foreach ($selectedAlbums as $albumId) {
            $albumInfo = $this->checkAlbumForUpdates($albumId, $yesterdayTimestamp, $userId);
            if ($albumInfo) {
                $updatedAlbums[] = $albumInfo;
                $this->logger->debug('Found updates in album: ' . $albumId . ' for user: ' . $userId, ['app' => 'album_notifications']);
            }
        }

        // Send notification email if there are updates
        if (!empty($updatedAlbums)) {
            $this->sendNotificationEmail($user, $updatedAlbums);
            $this->logger->info('Sent notification to ' . $userId . ' for ' . count($updatedAlbums) . ' updated albums', ['app' => 'album_notifications']);
        } else {
            $this->logger->debug('No album updates found for user ' . $userId, ['app' => 'album_notifications']);
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
        if (!$this->appManager->isEnabledForUser('photos', $userId)) {
            $this->logger->debug('Photos app not enabled for user ' . $userId, ['app' => 'album_notifications']);
            return null;
        }

        $realAlbumId = str_replace('photos_', '', $albumId);

        try {
            // Get album info first
            $albumData = $this->getPhotosAlbumInfo($realAlbumId, $userId);
            
            if (!$albumData) {
                $this->logger->debug('User ' . $userId . ' does not have access to Photos album ' . $realAlbumId, ['app' => 'album_notifications']);
                return null;
            }

            // Check for new photos using file addition timestamps instead of last_added_photo
            $photoCount = $this->countNewPhotosInPhotosAlbum($realAlbumId, $yesterdayTimestamp, $userId);
            
            if ($photoCount > 0) {
                $this->logger->debug('Found ' . $photoCount . ' new photos in Photos album ' . $realAlbumId . ' for user ' . $userId, ['app' => 'album_notifications']);
                
                return [
                    'name' => $albumData['name'] ?: 'Unnamed Album',
                    'source' => 'Photos',
                    'photo_count' => $photoCount,
                    'owner' => $albumData['owner'],
                    'shared' => $albumData['shared']
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error checking Photos album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return null;
    }

    private function checkMemoriesAlbumForUpdates(string $albumId, int $yesterdayTimestamp, string $userId): ?array {
        if (!$this->appManager->isEnabledForUser('memories', $userId)) {
            $this->logger->debug('Memories app not enabled for user ' . $userId, ['app' => 'album_notifications']);
            return null;
        }

        $realAlbumId = str_replace('memories_', '', $albumId);

        try {
            // Get album info first
            $albumData = $this->getMemoriesAlbumInfo($realAlbumId, $userId);
            
            if (!$albumData) {
                $this->logger->debug('User ' . $userId . ' does not have access to Memories album ' . $realAlbumId, ['app' => 'album_notifications']);
                return null;
            }

            // Check for new photos using file addition timestamps
            $photoCount = $this->countNewPhotosInMemoriesAlbum($realAlbumId, $yesterdayTimestamp, $userId);
            
            if ($photoCount > 0) {
                $this->logger->debug('Found ' . $photoCount . ' new photos in Memories album ' . $realAlbumId . ' for user ' . $userId, ['app' => 'album_notifications']);
                
                return [
                    'name' => $albumData['name'] ?: 'Unnamed Album',
                    'source' => 'Memories',
                    'photo_count' => $photoCount,
                    'owner' => $albumData['owner'],
                    'shared' => $albumData['shared']
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error checking Memories album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return null;
    }

    /**
     * Get Photos album info if user has access (owns OR is shared with)
     */
    private function getPhotosAlbumInfo(string $albumId, string $userId): ?array {
        // Check if photos_albums table exists
        if (!$this->tableExists('photos_albums')) {
            $this->logger->debug('photos_albums table does not exist', ['app' => 'album_notifications']);
            return null;
        }

        // First check if user owns the album
        $qb = $this->db->getQueryBuilder();
        $qb->select('name', 'user')
           ->from('photos_albums')
           ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId)))
           ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $albumData = $result->fetch();
        $result->closeCursor();

        if ($albumData) {
            return [
                'name' => $albumData['name'],
                'owner' => $albumData['user'],
                'shared' => false
            ];
        }

        // If not owned, check if shared with user
        if ($this->tableExists('photos_albums_collabs')) {
            // Check direct user sharing
            $qb = $this->db->getQueryBuilder();
            $qb->select('pa.name', 'pa.user')
               ->from('photos_albums', 'pa')
               ->innerJoin('pa', 'photos_albums_collabs', 'pac', 'pa.album_id = pac.album_id')
               ->where($qb->expr()->eq('pa.album_id', $qb->createNamedParameter($albumId)))
               ->andWhere($qb->expr()->eq('pac.collaborator_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('pac.collaborator_type', $qb->createNamedParameter(0))); // 0 = user

            $result = $qb->executeQuery();
            $albumData = $result->fetch();
            $result->closeCursor();

            if ($albumData) {
                return [
                    'name' => $albumData['name'],
                    'owner' => $albumData['user'],
                    'shared' => true
                ];
            }

            // Check group-based sharing
            if ($this->tableExists('group_user')) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('pa.name', 'pa.user')
                   ->from('photos_albums', 'pa')
                   ->innerJoin('pa', 'photos_albums_collabs', 'pac', 'pa.album_id = pac.album_id')
                   ->innerJoin('pac', 'group_user', 'gu', 'pac.collaborator_id = gu.gid')
                   ->where($qb->expr()->eq('pa.album_id', $qb->createNamedParameter($albumId)))
                   ->andWhere($qb->expr()->eq('gu.uid', $qb->createNamedParameter($userId)))
                   ->andWhere($qb->expr()->eq('pac.collaborator_type', $qb->createNamedParameter(1))); // 1 = group

                $result = $qb->executeQuery();
                $albumData = $result->fetch();
                $result->closeCursor();

                if ($albumData) {
                    return [
                        'name' => $albumData['name'],
                        'owner' => $albumData['user'],
                        'shared' => true
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get Memories album info if user has access (owns OR is shared with)
     */
    private function getMemoriesAlbumInfo(string $albumId, string $userId): ?array {
        // Try common table names for Memories
        $possibleTables = ['memories_albums', 'oc_memories_albums'];
        
        foreach ($possibleTables as $tableName) {
            if (!$this->tableExists($tableName)) {
                continue;
            }

            // First check if user owns the album
            $qb = $this->db->getQueryBuilder();
            $qb->select('name', 'user')
               ->from($tableName)
               ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId)))
               ->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
            $albumData = $result->fetch();
            $result->closeCursor();

            if ($albumData) {
                return [
                    'name' => $albumData['name'],
                    'owner' => $albumData['user'],
                    'shared' => false
                ];
            }

            // If not owned, check if shared with user
            $collabTableName = str_replace('_albums', '_albums_collabs', $tableName);
            if ($this->tableExists($collabTableName)) {
                // Check direct user sharing
                $qb = $this->db->getQueryBuilder();
                $qb->select('ma.name', 'ma.user')
                   ->from($tableName, 'ma')
                   ->innerJoin('ma', $collabTableName, 'mac', 'ma.album_id = mac.album_id')
                   ->where($qb->expr()->eq('ma.album_id', $qb->createNamedParameter($albumId)))
                   ->andWhere($qb->expr()->eq('mac.collaborator_id', $qb->createNamedParameter($userId)))
                   ->andWhere($qb->expr()->eq('mac.collaborator_type', $qb->createNamedParameter(0))); // 0 = user

                $result = $qb->executeQuery();
                $albumData = $result->fetch();
                $result->closeCursor();

                if ($albumData) {
                    return [
                        'name' => $albumData['name'],
                        'owner' => $albumData['user'],
                        'shared' => true
                    ];
                }

                // Check group-based sharing
                if ($this->tableExists('group_user')) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('ma.name', 'ma.user')
                       ->from($tableName, 'ma')
                       ->innerJoin('ma', $collabTableName, 'mac', 'ma.album_id = mac.album_id')
                       ->innerJoin('mac', 'group_user', 'gu', 'mac.collaborator_id = gu.gid')
                       ->where($qb->expr()->eq('ma.album_id', $qb->createNamedParameter($albumId)))
                       ->andWhere($qb->expr()->eq('gu.uid', $qb->createNamedParameter($userId)))
                       ->andWhere($qb->expr()->eq('mac.collaborator_type', $qb->createNamedParameter(1))); // 1 = group

                    $result = $qb->executeQuery();
                    $albumData = $result->fetch();
                    $result->closeCursor();

                    if ($albumData) {
                        return [
                            'name' => $albumData['name'],
                            'owner' => $albumData['user'],
                            'shared' => true
                        ];
                    }
                }
            }

            break; // Found working table, stop trying others
        }

        return null;
    }

    private function countNewPhotosInPhotosAlbum(string $albumId, int $yesterdayTimestamp, string $userId): int {
        try {
            if (!$this->tableExists('photos_albums_files')) {
                $this->logger->debug('photos_albums_files table does not exist', ['app' => 'album_notifications']);
                return 0;
            }

            // Check for files added to the album in the last 24 hours
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as count'))
               ->from('photos_albums_files')
               ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId)));

            // If there's an added_at field, use it
            if ($this->columnExists('photos_albums_files', 'added_at')) {
                $qb->andWhere($qb->expr()->gte('added_at', $qb->createNamedParameter($yesterdayTimestamp)));
            } else {
                // Fallback: join with filecache to check mtime
                $qb->innerJoin('paf', 'filecache', 'fc', 'paf.file_id = fc.fileid')
                   ->andWhere($qb->expr()->gte('fc.mtime', $qb->createNamedParameter($yesterdayTimestamp)))
                   // Only include files that the user can access
                   ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%')));
            }

            $result = $qb->executeQuery();
            $count = $result->fetchOne();
            $result->closeCursor();
            
            return (int)$count;
        } catch (\Exception $e) {
            $this->logger->error('Error counting new photos in Photos album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
            return 0;
        }
    }

    private function countNewPhotosInMemoriesAlbum(string $albumId, int $yesterdayTimestamp, string $userId): int {
        try {
            $possibleTables = ['memories_albums_files', 'oc_memories_albums_files'];
            
            foreach ($possibleTables as $tableName) {
                if (!$this->tableExists($tableName)) {
                    continue;
                }

                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*) as count'))
                   ->from($tableName)
                   ->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId)));

                // If there's an added_at field, use it
                if ($this->columnExists($tableName, 'added_at')) {
                    $qb->andWhere($qb->expr()->gte('added_at', $qb->createNamedParameter($yesterdayTimestamp)));
                } else {
                    // Fallback: join with filecache to check mtime
                    $qb->innerJoin('maf', 'filecache', 'fc', 'maf.file_id = fc.fileid')
                       ->andWhere($qb->expr()->gte('fc.mtime', $qb->createNamedParameter($yesterdayTimestamp)))
                       ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%')));
                }

                $result = $qb->executeQuery();
                $count = $result->fetchOne();
                $result->closeCursor();
                
                return (int)$count;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error counting new photos in Memories album ' . $albumId . ': ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return 0;
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
            
            // Add sharing info to album name display
            $albumDisplayName = htmlspecialchars($album['name']);
            if ($album['shared']) {
                $albumDisplayName .= ' <span style="color: #666; font-size: 12px;">(shared by ' . htmlspecialchars($album['owner']) . ')</span>';
            }
            
            $albumsHtml .= '
                <div style="background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #0082c9;">
                    <h3 style="margin: 0 0 5px 0; color: #333;">' . $albumDisplayName . '</h3>
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

    private function columnExists(string $tableName, string $columnName): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($columnName)
               ->from($tableName)
               ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}