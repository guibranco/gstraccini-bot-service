<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ReviewEffortFileClassifier;
use PHPUnit\Framework\TestCase;

/**
 * How ReviewEffortFileClassifier sorts the files of a pull request.
 */
class ReviewEffortFileClassifierTest extends TestCase
{
    /** A source file of the repository. */
    private const SOURCE_FILE = "Src/Library/LabelService.php";

    /**
     * The classifier under test.
     *
     * @var ReviewEffortFileClassifier
     */
    private ReviewEffortFileClassifier $classifier;


    /**
     * Starts each test with a classifier.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->classifier = new ReviewEffortFileClassifier();

    }//end setUp()


    /**
     * Each kind of file is told by its path.
     *
     * @return void
     */
    public function testKindOfEachKindOfFile(): void
    {
        $expected = [];
        $expected[self::SOURCE_FILE] = "source";
        $expected["Src/Tests/Library/LabelServiceTest.php"] = "test";
        $expected["web/src/app.test.ts"]         = "test";
        $expected["pkg/parser_test.go"]          = "test";
        $expected["tests/test_parser.py"]        = "test";
        $expected["Sql/0018.add_table.sql"]      = "sql";
        $expected["db/migrations/2026_add.php"]  = "sql";
        $expected[".github/workflows/build.yml"] = "infra";
        $expected["Dockerfile"]                  = "infra";
        $expected["docker-compose.yml"]          = "infra";
        $expected["deploy/run.sh"]               = "infra";
        $expected["README.md"]                   = "docs";
        $expected["docs/adr/0001.md"]            = "docs";
        $expected["LICENSE"]                     = "docs";
        $expected["Src/config/labels.json"]      = "config";
        $expected[".editorconfig"]               = "config";

        foreach ($expected as $path => $kind) {
            $this->assertSame($kind, $this->classifier->kindOf($path), $path);
        }

    }//end testKindOfEachKindOfFile()


    /**
     * A name ending in "test" is only a test when "Test" starts a word.
     *
     * @return void
     */
    public function testLatestIsNotATest(): void
    {
        $this->assertSame("source", $this->classifier->kindOf("Src/latest.php"));
        $this->assertSame("test", $this->classifier->kindOf("Src/LatestTest.php"));

    }//end testLatestIsNotATest()


    /**
     * Docs weigh the least and SQL the most, with source in between.
     *
     * @return void
     */
    public function testWeightsGrowFromDocsToSql(): void
    {
        $weights = array_map(fn (string $kind) => $this->classifier->weightOf($kind), ["docs", "test", "config", "source", "infra", "sql"]);

        $sorted = $weights;
        sort($sorted);
        $this->assertSame($sorted, $weights);
        $this->assertSame(1.0, $this->classifier->weightOf("source"));

    }//end testWeightsGrowFromDocsToSql()


    /**
     * Source, tests, infrastructure and SQL are code; docs and config are not.
     *
     * @return void
     */
    public function testCodeKinds(): void
    {
        $this->assertTrue($this->classifier->isCode("source"));
        $this->assertTrue($this->classifier->isCode("sql"));
        $this->assertFalse($this->classifier->isCode("docs"));
        $this->assertFalse($this->classifier->isCode("config"));

    }//end testCodeKinds()


    /**
     * Auth, secrets, migrations, CI and deployment files are sensitive.
     *
     * @return void
     */
    public function testSensitivePaths(): void
    {
        $paths   = [];
        $paths[] = "Src/Auth/LoginHandler.php";
        $paths[] = "Src/Library/DatabaseTokenStore.php";
        $paths[] = "Src/Library/CryptoHelper.php";
        $paths[] = "db/migrations/2026_add.php";
        $paths[] = "Sql/0018.add_table.sql";
        $paths[] = ".github/workflows/deploy.yml";
        $paths[] = "Dockerfile";
        $paths[] = ".env.example";

        foreach ($paths as $path) {
            $this->assertTrue($this->classifier->isRisky($path), $path);
        }

    }//end testSensitivePaths()


    /**
     * Ordinary files are not sensitive.
     *
     * @return void
     */
    public function testOrdinaryPathsAreNotSensitive(): void
    {
        $this->assertFalse($this->classifier->isRisky(self::SOURCE_FILE));
        $this->assertFalse($this->classifier->isRisky("README.md"));
        $this->assertFalse($this->classifier->isRisky("Src/Library/Author.php"));

    }//end testOrdinaryPathsAreNotSensitive()


}//end class
