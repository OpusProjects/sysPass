<?php

declare(strict_types=1);
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Tests\Unit\Infrastructure\File;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\File\FileCache;

/**
 * Class FileCacheTest
 *
 * FileCache is what the ACL cache (among others) is stored and read back through, so a load()
 * that silently returns the wrong value, or a class check that can be tricked, is a stale- or
 * wrong-permissions bug in production, not just a broken cache file. This covers FileCache's own
 * (de)serialization (load/save/loadWith) on top of what FileCacheBaseTest already covers for the
 * path-locating and expiry logic FileCache inherits unchanged from FileCacheBase.
 *
 * Uses real temporary files: the underlying FileHandler extends SplFileObject and opens its file
 * eagerly in its constructor, so this can't be exercised reliably against vfsStream.
 */
#[Group('unitary')]
class FileCacheTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_filecache_', true);
        mkdir($this->dir);
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'cache.dat';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @throws FileException
     * @throws SPException
     */
    public function testSaveThenLoadRoundTripsAnArray(): void
    {
        $cache = new FileCache($this->file);
        $data = ['id' => 42, 'name' => 'account acl', 'nested' => ['a', 'b']];

        $cache->save($data);

        self::assertSame($data, $cache->load());
    }

    /**
     * The ACL cache stores hydrated objects, not arrays, so a round trip has to preserve the
     * stored type, not just produce something scalar-equal to it.
     *
     * @throws FileException
     * @throws SPException
     */
    public function testSaveThenLoadRoundTripsAnObject(): void
    {
        $cache = new FileCache($this->file);
        $data = new FileCacheTestFixture('acl-cache', 7);

        $cache->save($data);
        $loaded = $cache->loadWith(FileCacheTestFixture::class);

        self::assertInstanceOf(FileCacheTestFixture::class, $loaded);
        self::assertEquals($data, $loaded);
    }

    /**
     * load() reads data, and a cache file that contains an object does not become one.
     *
     * It used to: nothing named a class, and Serde::deserialize() allows every class when it is
     * not told which to expect — so whatever a cache file happened to contain was instantiated,
     * and a write into the cache directory became objects of somebody else's choosing on the next
     * read. Callers that cache an object say which one, through loadWith().
     */
    public function testLoadDoesNotBuildObjects(): void
    {
        $cache = new FileCache($this->file);

        $cache->save(new FileCacheTestFixture('acl-cache', 7));

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $cache->load());
    }

    /**
     * A cache holding an *array* of objects names its classes through load().
     *
     * loadWith() cannot express this — it answers with one object of the class it was given —
     * and the actions cache is exactly this shape: `Action[]`. Restricting load() to data alone
     * turned every entry into __PHP_Incomplete_Class, and the failure surfaced far away, as
     * `Actions::getActionById(): Return value must be of type Action, __PHP_Incomplete_Class
     * returned`.
     */
    public function testLoadNamesTheClassesAnArrayMayContain(): void
    {
        $cache = new FileCache($this->file);

        $cache->save([new FileCacheNestedFixture('one'), new FileCacheNestedFixture('two')]);

        $refused = $cache->load();
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $refused[0]);

        $allowed = $cache->load(null, FileCacheNestedFixture::class);
        self::assertInstanceOf(FileCacheNestedFixture::class, $allowed[0]);
        self::assertSame('two', $allowed[1]->label);
    }

    /**
     * An object that holds other objects needs all of them named, or the ones inside come back as
     * __PHP_Incomplete_Class while the object around them still passes instanceof — which is how a
     * restriction can look applied and leave the contents hollow.
     *
     * The property here is untyped on purpose. A *typed* property refuses the incomplete class
     * outright with a TypeError, which is loud and therefore harmless; it is the untyped and the
     * array-valued ones that hold it quietly. ThemeIcons keeps its icons in an array, which is why
     * it checks its contents after loading rather than trusting the class it asked for.
     */
    public function testLoadWithNamesNestedClassesToo(): void
    {
        $cache = new FileCache($this->file);

        $cache->save(new FileCacheTestFixture('acl-cache', 7, new FileCacheNestedFixture('nested')));

        $hollow = $cache->loadWith(FileCacheTestFixture::class);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $hollow->nested);

        $whole = $cache->loadWith(FileCacheTestFixture::class, FileCacheNestedFixture::class);
        self::assertInstanceOf(FileCacheNestedFixture::class, $whole->nested);
    }

    /**
     * A FileCache built without a constructor path adopts whatever path its first call is given
     * instead — this is FileCache's own save()/load() built on top of checkOrInitializePath(),
     * which FileCacheBaseTest already exercises in isolation from FileCache's (de)serialization.
     *
     * @throws FileException
     * @throws SPException
     */
    public function testSaveWithoutAConstructorPathAdoptsTheGivenPath(): void
    {
        $cache = new FileCache();
        $data = ['adopted' => 'path value'];

        $cache->save($data, $this->file);

        self::assertSame($data, $cache->load());
    }

    /**
     * A cache file removed out from under an already-open FileCache (e.g. cleared by another
     * process while this one still holds a handle) must fail loudly on the next load() rather
     * than handing back stale or garbage data.
     */
    public function testLoadThrowsWhenTheCacheFileIsMissing(): void
    {
        $cache = new FileCache($this->file);
        unlink($this->file);

        $this->expectException(FileException::class);

        $cache->load();
    }

    /**
     * A payload that is not valid serialized PHP (e.g. truncated by a crash mid-write, or the
     * cache format having changed) must be reported as a decode failure, not handed back to the
     * caller as if it were real cached data.
     */
    public function testLoadThrowsWhenThePayloadIsCorrupt(): void
    {
        file_put_contents($this->file, 'this is not a serialized payload');
        $cache = new FileCache($this->file);

        try {
            $cache->load();
            self::fail('Expected an SPException');
        } catch (SPException $e) {
            self::assertSame("Couldn't deserialize the data", $e->getMessage());
        }
    }

    /**
     * loadWith() round-trips an instance of the requested class, which is what lets a caller ask
     * for a typed object back instead of a bare deserialized value.
     *
     * @throws FileException
     * @throws SPException
     * @throws InvalidClassException
     */
    public function testLoadWithReturnsAnInstanceOfTheRequestedClass(): void
    {
        $cache = new FileCache($this->file);
        $cache->save(new FileCacheTestFixture('acl-cache', 7));

        $loaded = $cache->loadWith(FileCacheTestFixture::class);

        self::assertInstanceOf(FileCacheTestFixture::class, $loaded);
        self::assertSame('acl-cache', $loaded->name);
        self::assertSame(7, $loaded->value);
    }

    /**
     * A cache written for one shape (here: a plain array) must not be silently accepted as
     * another shape just because a caller asked for a specific class — that is exactly the kind
     * of mismatch that would let stale data masquerade as a fresh ACL object.
     *
     * @throws FileException
     * @throws SPException
     */
    public function testLoadWithThrowsWhenTheStoredDataIsNotAnInstanceOfTheRequestedClass(): void
    {
        $cache = new FileCache($this->file);
        $cache->save(['not' => 'an object']);

        try {
            $cache->loadWith(FileCacheTestFixture::class);
            self::fail('Expected an InvalidClassException');
        } catch (InvalidClassException $e) {
            self::assertSame(
                'Either class does not exist or file data cannot unserialized into: ' . FileCacheTestFixture::class,
                $e->getMessage()
            );
        }
    }

    /**
     * Asking for a class that does not exist at all must fail through the same explicit
     * exception as a type mismatch, rather than a bare engine "class not found" error.
     *
     * @throws FileException
     * @throws SPException
     */
    public function testLoadWithThrowsWhenTheRequestedClassDoesNotExist(): void
    {
        $cache = new FileCache($this->file);
        $cache->save(['irrelevant' => 'payload']);
        $missingClass = self::class . 'NoSuchFixture';

        try {
            $cache->loadWith($missingClass);
            self::fail('Expected an InvalidClassException');
        } catch (InvalidClassException $e) {
            self::assertSame(
                'Either class does not exist or file data cannot unserialized into: ' . $missingClass,
                $e->getMessage()
            );
        }
    }

    /**
     * A payload that fails to unserialize at all (rather than unserializing into the wrong
     * shape) must be reported through the same InvalidClassException as any other mismatch, not
     * surfaced as a bare engine warning.
     */
    public function testLoadWithThrowsWhenThePayloadIsCorrupt(): void
    {
        file_put_contents($this->file, 'this is not a serialized payload');
        $cache = new FileCache($this->file);

        try {
            // unserialize() emits a PHP warning for invalid data; @ mirrors how the production
            // call site itself does not (and cannot) suppress it, since the exception below is
            // what the caller is meant to rely on instead.
            @$cache->loadWith(FileCacheTestFixture::class);
            self::fail('Expected an InvalidClassException');
        } catch (InvalidClassException $e) {
            self::assertSame(
                'Either class does not exist or file data cannot unserialized into: ' . FileCacheTestFixture::class,
                $e->getMessage()
            );
        }
    }
}

/**
 * A minimal concrete class to round-trip through FileCache::save()/load()/loadWith(), which need
 * a real, loadable class name to check the deserialized data against.
 */
final class FileCacheTestFixture
{
    public function __construct(
        public readonly string $name,
        public readonly int $value,
        public readonly mixed $nested = null
    ) {
    }
}

/**
 * A different class, so that naming the outer one is not enough to bring it back.
 */
final class FileCacheNestedFixture
{
    public function __construct(public readonly string $label)
    {
    }
}
