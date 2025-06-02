<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $db;
    private $appManager;
    private $logger;
    private $userId;

    public function __construct(
        IUserSession $userSession,
        IConfig $config,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->appManager = $appManager;
        $this->logger = $logger;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();
        $albums = [];

        // Get albums from Photos and Memories apps
        $albums = array_merge(
            $this->getPhotosAlbums(),
            $this->getMemoriesAlbums()
        );

        // Remove duplicates based on album ID
        $uniqueAlbums = [];
        $seenIds = [];
        foreach ($albums as $album) {
            if (!in_array($album['id'], $seenIds)) {
                $uniqueAlbums[] = $album;
                $seenIds[] = $album['id'];
            }
        }

        // Get selected albums from config
        $selectedAlbumsJson = $this->config->getUserValue($this->userId, 'album_notifications', 'selected_albums', '[]');
        $selected_albums = json_decode($selectedAlbumsJson, true) ?? [];

        $parameters = [
            'user' => $displayName,
            'albums' => $uniqueAlbums,
            'selected_albums' => $selected_albums,
        ];
        return new TemplateResponse('album_notifications', 'settings', $parameters);
    }

    private function getPhotosAlbums(): array {
        $albums = [];

        // Check if Photos app is enabled
        if (!$this->appManager->isEnabledForUser('photos')) {
            return $albums;
        }

        try {
            if ($this->tableExists('photos_albums')) {
                // Get albums created by the user
                $qb = $this->db->getQueryBuilder();
                $qb->select('album_id', 'name', 'user')
                   ->from('photos_albums')
                   ->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)));

                $result = $qb->executeQuery();
                while ($row = $result->fetch()) {
                    $albums[] = [
                        'id' => 'photos_' . $row['album_id'],
                        'name' => $row['name'] ?: 'Unnamed Album',
                        'source' => 'Photos',
                        'owner' => $row['user'],
                        'shared' => false
                    ];
                }
                $result->closeCursor();

                // Get albums shared with the user (collaborative albums)
                if ($this->tableExists('photos_albums_collabs')) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('pa.album_id', 'pa.name', 'pa.user', 'pac.collaborator_id', 'pac.collaborator_type')
                       ->from('photos_albums', 'pa')
                       ->innerJoin('pa', 'photos_albums_collabs', 'pac', 'pa.album_id = pac.album_id')
                       ->where($qb->expr()->eq('pac.collaborator_id', $qb->createNamedParameter($this->userId)))
                       ->andWhere($qb->expr()->eq('pac.collaborator_type', $qb->createNamedParameter(0))) // 0 = user
                       ->andWhere($qb->expr()->neq('pa.user', $qb->createNamedParameter($this->userId))); // Exclude own albums

                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $albums[] = [
                            'id' => 'photos_' . $row['album_id'],
                            'name' => ($row['name'] ?: 'Unnamed Album') . ' (shared by ' . $row['user'] . ')',
                            'source' => 'Photos',
                            'owner' => $row['user'],
                            'shared' => true
                        ];
                    }
                    $result->closeCursor();
                }

                // Also check for group-based sharing if photos_albums_collabs supports it
                if ($this->tableExists('photos_albums_collabs') && $this->tableExists('group_user')) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('pa.album_id', 'pa.name', 'pa.user', 'pac.collaborator_id')
                       ->from('photos_albums', 'pa')
                       ->innerJoin('pa', 'photos_albums_collabs', 'pac', 'pa.album_id = pac.album_id')
                       ->innerJoin('pac', 'group_user', 'gu', 'pac.collaborator_id = gu.gid')
                       ->where($qb->expr()->eq('gu.uid', $qb->createNamedParameter($this->userId)))
                       ->andWhere($qb->expr()->eq('pac.collaborator_type', $qb->createNamedParameter(1))) // 1 = group
                       ->andWhere($qb->expr()->neq('pa.user', $qb->createNamedParameter($this->userId))); // Exclude own albums

                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $albumId = 'photos_' . $row['album_id'];
                        // Check if we already added this album (avoid duplicates)
                        $exists = false;
                        foreach ($albums as $existingAlbum) {
                            if ($existingAlbum['id'] === $albumId) {
                                $exists = true;
                                break;
                            }
                        }
                        
                        if (!$exists) {
                            $albums[] = [
                                'id' => $albumId,
                                'name' => ($row['name'] ?: 'Unnamed Album') . ' (shared via group)',
                                'source' => 'Photos',
                                'owner' => $row['user'],
                                'shared' => true
                            ];
                        }
                    }
                    $result->closeCursor();
                }

                $this->logger->log(LogLevel::DEBUG, 'Found ' . count($albums) . ' Photos albums (including shared)', ['app' => 'album_notifications']);
            }
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch Photos albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return $albums;
    }

    private function getMemoriesAlbums(): array {
        $albums = [];

        // Check if Memories app is enabled
        if (!$this->appManager->isEnabledForUser('memories')) {
            return $albums;
        }

        try {
            // Try common table names for Memories
            $possibleTables = ['memories_albums', 'oc_memories_albums'];
            
            foreach ($possibleTables as $tableName) {
                if ($this->tableExists($tableName)) {
                    // Get albums created by the user
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('album_id', 'name', 'user')
                       ->from($tableName)
                       ->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)));

                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $albums[] = [
                            'id' => 'memories_' . $row['album_id'],
                            'name' => $row['name'] ?: 'Unnamed Album',
                            'source' => 'Memories',
                            'owner' => $row['user'],
                            'shared' => false
                        ];
                    }
                    $result->closeCursor();

                    // Get albums shared with the user (collaborative albums)
                    $collabTableName = str_replace('_albums', '_albums_collabs', $tableName);
                    if ($this->tableExists($collabTableName)) {
                        $qb = $this->db->getQueryBuilder();
                        $qb->select('ma.album_id', 'ma.name', 'ma.user', 'mac.collaborator_id', 'mac.collaborator_type')
                           ->from($tableName, 'ma')
                           ->innerJoin('ma', $collabTableName, 'mac', 'ma.album_id = mac.album_id')
                           ->where($qb->expr()->eq('mac.collaborator_id', $qb->createNamedParameter($this->userId)))
                           ->andWhere($qb->expr()->eq('mac.collaborator_type', $qb->createNamedParameter(0))) // 0 = user
                           ->andWhere($qb->expr()->neq('ma.user', $qb->createNamedParameter($this->userId))); // Exclude own albums

                        $result = $qb->executeQuery();
                        while ($row = $result->fetch()) {
                            $albums[] = [
                                'id' => 'memories_' . $row['album_id'],
                                'name' => ($row['name'] ?: 'Unnamed Album') . ' (shared by ' . $row['user'] . ')',
                                'source' => 'Memories',
                                'owner' => $row['user'],
                                'shared' => true
                            ];
                        }
                        $result->closeCursor();

                        // Also check for group-based sharing
                        if ($this->tableExists('group_user')) {
                            $qb = $this->db->getQueryBuilder();
                            $qb->select('ma.album_id', 'ma.name', 'ma.user', 'mac.collaborator_id')
                               ->from($tableName, 'ma')
                               ->innerJoin('ma', $collabTableName, 'mac', 'ma.album_id = mac.album_id')
                               ->innerJoin('mac', 'group_user', 'gu', 'mac.collaborator_id = gu.gid')
                               ->where($qb->expr()->eq('gu.uid', $qb->createNamedParameter($this->userId)))
                               ->andWhere($qb->expr()->eq('mac.collaborator_type', $qb->createNamedParameter(1))) // 1 = group
                               ->andWhere($qb->expr()->neq('ma.user', $qb->createNamedParameter($this->userId))); // Exclude own albums

                            $result = $qb->executeQuery();
                            while ($row = $result->fetch()) {
                                $albumId = 'memories_' . $row['album_id'];
                                // Check if we already added this album (avoid duplicates)
                                $exists = false;
                                foreach ($albums as $existingAlbum) {
                                    if ($existingAlbum['id'] === $albumId) {
                                        $exists = true;
                                        break;
                                    }
                                }
                                
                                if (!$exists) {
                                    $albums[] = [
                                        'id' => $albumId,
                                        'name' => ($row['name'] ?: 'Unnamed Album') . ' (shared via group)',
                                        'source' => 'Memories',
                                        'owner' => $row['user'],
                                        'shared' => true
                                    ];
                                }
                            }
                            $result->closeCursor();
                        }
                    }

                    $this->logger->log(LogLevel::DEBUG, 'Found ' . count($albums) . ' Memories albums from table: ' . $tableName . ' (including shared)', ['app' => 'album_notifications']);
                    break; // Found working table, stop trying others
                }
            }
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, 'Failed to fetch Memories albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
        }

        return $albums;
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

    public function getSection() {
        return 'album_notifications';
    }

    public function getPriority() {
        return 10;
    }
}