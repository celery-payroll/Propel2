<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Adapter\Pdo;

use DateTime;
use DateTimeZone;
use Propel\Generator\Model\PropelTypes;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Map\ColumnMap;
use Propel\Runtime\Map\DatabaseMap;
use Propel\Runtime\Map\TableMap;
use Propel\Tests\TestCase;

/**
 * Regression coverage for PdoAdapter::formatTemporalValue() — the binding-time
 * formatter used by every UPDATE/INSERT through Criteria.
 *
 * @group adapter
 */
class PdoAdapterFormatTemporalValueTest extends TestCase
{
    /**
     * @var MysqlAdapter
     */
    private $adapter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->adapter = new MysqlAdapter();
    }

    /**
     * Reproduces the bug where saving a DATE column from a DateTime carrying a
     * timezone east of America/Curacao writes the previous day to the DB. The
     * original implementation funnelled every value through PropelDateTime::newInstance
     * with America/Curacao, which shifted the wall-clock past midnight backwards.
     *
     * @return void
     */
    public function testDateColumnPreservesCalendarDateForDateTimeEastOfCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::DATE);
        $value = new DateTime('1979-03-30 00:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('1979-03-30', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * @return void
     */
    public function testDateColumnPreservesCalendarDateForDateTimeFarEastOfCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::DATE);
        $value = new DateTime('1979-03-30 00:00:00', new DateTimeZone('Asia/Tokyo'));

        $this->assertSame('1979-03-30', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * @return void
     */
    public function testDateColumnPreservesCalendarDateForDateTimeWestOfCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::DATE);
        $value = new DateTime('1979-03-30 00:00:00', new DateTimeZone('America/Los_Angeles'));

        $this->assertSame('1979-03-30', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * @return void
     */
    public function testDateColumnPreservesCalendarDateForDateTimeInCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::DATE);
        $value = new DateTime('1979-03-30 00:00:00', new DateTimeZone('America/Curacao'));

        $this->assertSame('1979-03-30', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * BU_DATE shares the DATE behavior — both must skip the Curacao normalization.
     *
     * @return void
     */
    public function testBuDateColumnPreservesCalendarDateForDateTimeEastOfCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::BU_DATE);
        $value = new DateTime('1979-03-30 00:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('1979-03-30', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * @return void
     */
    public function testTimeColumnPreservesWallClockForDateTimeInDifferentTimezone(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::TIME);
        $value = new DateTime('1979-03-30 09:30:00', new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('09:30:00.000000', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * Non-DateTime values still flow through PropelDateTime::newInstance with the
     * Curacao timezone. A plain date string must round-trip unchanged.
     *
     * @return void
     */
    public function testDateColumnAcceptsRawDateString(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::DATE);

        $this->assertSame('1979-03-30', $this->adapter->formatTemporalValue('1979-03-30', $cMap));
    }

    /**
     * DATETIME columns must continue to normalize the wall-clock to America/Curacao.
     * 10:00 in Europe/Amsterdam (CET, UTC+1) corresponds to 05:00 in Curacao (UTC-4).
     *
     * @return void
     */
    public function testDateTimeColumnNormalizesWallClockToCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::DATETIME);
        $value = new DateTime('2025-01-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('2025-01-15 05:00:00.000000', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * TIMESTAMP shares DATETIME behavior — wall-clock must be anchored to Curacao.
     *
     * @return void
     */
    public function testTimestampColumnNormalizesWallClockToCuracao(): void
    {
        $cMap = $this->buildColumnMap(PropelTypes::TIMESTAMP);
        $value = new DateTime('2025-01-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('2025-01-15 05:00:00.000000', $this->adapter->formatTemporalValue($value, $cMap));
    }

    /**
     * @param string $type One of the PropelTypes::* temporal constants.
     * @return ColumnMap
     */
    private function buildColumnMap(string $type): ColumnMap
    {
        $databaseMap = new DatabaseMap('test_db');
        $tableMap = new TableMap('test_table', $databaseMap);

        return new ColumnMap('value', $tableMap, 'Value', $type);
    }
}
