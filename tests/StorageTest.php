<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class StorageTest extends TestCase {
    private string $tmpFile;

    protected function setUp(): void {
        \Database::resetInstance();
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_storage_test_' . bin2hex(random_bytes(6)) . '.db';
        if (file_exists($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    protected function tearDown(): void {
        \Database::resetInstance();
        if (file_exists($this->tmpFile) && is_file($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    public function testSaveItemCreatesAndAssignsId(): void {
        $db = \Database::getInstance($this->tmpFile);
        $item = $db->saveItem(['name' => 'alpha']);

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('createdAt', $item);
        $this->assertArrayHasKey('lastUpdatedAt', $item);
        $this->assertSame('alpha', $item['name']);

        // persisted
        \Database::resetInstance();
        $db2 = \Database::getInstance($this->tmpFile);
        $fetched = $db2->getItem($item['id']);
        $this->assertSame($item['id'], $fetched['id']);
        $this->assertSame('alpha', $fetched['name']);
    }

    public function testListIdsReturnsSortedInts(): void {
        $db = \Database::getInstance($this->tmpFile);
        $a = $db->saveItem(['name' => 'a']);
        $b = $db->saveItem(['name' => 'b']);
        $ids = $db->listIds();
        $this->assertSame([ $a['id'], $b['id'] ], $ids);
    }

    public function testUpdateItemPreservesCreatedAtAndUpdatesTimestamp(): void {
        $db = \Database::getInstance($this->tmpFile);
        $item = $db->saveItem(['name' => 'first']);
        $created = $item['createdAt'];

        // update
        sleep(1);
        $updated = $db->saveItem(['name' => 'second'], $item['id']);
        $this->assertSame($item['id'], $updated['id']);
        $this->assertSame($created, $updated['createdAt']);
        $this->assertNotSame($updated['lastUpdatedAt'], $created);
        $this->assertSame('second', $updated['name']);
    }

    public function testGetItemNotFoundReturnsNull(): void {
        $db = \Database::getInstance($this->tmpFile);
        $this->assertNull($db->getItem(9999));
    }

    public function testGetNextIdEmptyAndAfterSaves(): void {
        $db = \Database::getInstance($this->tmpFile);
        // when empty
        $this->assertSame(1, $db->getNextId());

        $a = $db->saveItem(['name' => 'one']);
        $b = $db->saveItem(['name' => 'two']);

        $this->assertSame(max([$a['id'], $b['id']]) + 1, $db->getNextId());
    }

    public function testSaveWithExplicitIdAdvancesGetNextId(): void {
        $db = \Database::getInstance($this->tmpFile);

        $db->saveItem(['name' => 'explicit'], 10);
        $this->assertSame(11, $db->getNextId());
        $this->assertSame('explicit', $db->getItem(10)['name']);
    }

    public function testPartialUpdateOverwritesMissingFields(): void {
        $db = \Database::getInstance($this->tmpFile);
        $item = $db->saveItem(['a' => 1, 'b' => 2]);

        // Update with only 'b' field — current behavior overwrites missing fields
        $db->saveItem(['b' => 3], $item['id']);
        $updated = $db->getItem($item['id']);

        $this->assertArrayHasKey('b', $updated);
        $this->assertSame(3, $updated['b']);
        $this->assertArrayNotHasKey('a', $updated);
    }

    public function testGetNextIdWithNonSequentialIds(): void {
        $db = \Database::getInstance($this->tmpFile);
        $db->saveItem(['name' => 'five'], 5);
        $db->saveItem(['name' => 'two'], 2);
        $this->assertSame(6, $db->getNextId());
    }

    public function testSaveWithExistingIdOverwritesAndUpdatesTimestamps(): void {
        $db = \Database::getInstance($this->tmpFile);
        $item = $db->saveItem(['name' => 'orig']);
        $id = $item['id'];
        $created = $item['createdAt'];

        sleep(1);
        $updated = $db->saveItem(['name' => 'updated'], $id);
        $this->assertSame($id, $updated['id']);
        $this->assertSame($created, $updated['createdAt']);
        $this->assertNotSame($created, $updated['lastUpdatedAt']);
        $this->assertSame('updated', $updated['name']);
    }

    public function testSaveItemToDirectoryPathThrowsStorageException(): void {
        $dirPath = sys_get_temp_dir() . '/php_rest_storage_test_dir_' . bin2hex(random_bytes(6));
        mkdir($dirPath, 0775);

        $this->expectException(\StorageException::class);
        // For SQLite, we need to try to use the directory as a database file
        $dbPath = $dirPath; // Using directory as database path should fail
        $db = \Database::getInstance($dbPath);
        $db->saveItem(['name' => 'x']);

        if (is_dir($dirPath)) @rmdir($dirPath);
    }
}
