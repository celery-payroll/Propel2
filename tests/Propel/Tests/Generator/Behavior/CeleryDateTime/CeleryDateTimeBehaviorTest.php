<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Behavior\CeleryDateTime;

use Propel\Generator\Behavior\CeleryDateTime\CeleryDateTimeBehavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\Database;
use Propel\Generator\Util\QuickBuilder;
use Propel\Tests\TestCase;

/**
 * Tests for CeleryDateTimeBehavior.
 *
 * @group model
 */
class CeleryDateTimeBehaviorTest extends TestCase
{
    /**
     * Build the generated classes once per test class. The behavior overrides the
     * temporal getters/setters on a DATETIME, a DATE and a TIME column so each
     * branch can be exercised independently.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        if (class_exists('\CeleryTz\Event')) {
            return;
        }

        $schema = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<database name="celery_tz" defaultIdMethod="native" namespace="CeleryTz">
    <table name="event">
        <column name="id" type="integer" required="true" primaryKey="true" autoIncrement="true"/>
        <column name="occurred_at" type="TIMESTAMP"/>
        <column name="event_date" type="DATE"/>
        <column name="event_time" type="TIME"/>
        <behavior name="celery_date_time"/>
    </table>
</database>
XML;
        $builder = new QuickBuilder();
        $builder->setSchema($schema);
        $builder->buildClasses(null, true);
    }

    /**
     * Reproduces the exact state after DB hydration: PDO loads the stored Curacao
     * wall-clock string into a DateTime tagged with the PHP runtime default
     * timezone. Assigning the property directly bypasses the setter (which would
     * otherwise normalize the value via PropelDateTime::newInstance).
     *
     * @return void
     */
    public function testDateTimeGetterConvertsHydratedCuracaoValueToLocalTimezone()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');

            $event = new \CeleryTz\Event();
            $hydrated = new \DateTime('2026-04-27 12:00:00', new \DateTimeZone('UTC'));
            $reflection = new \ReflectionProperty($event, 'occurred_at');
            $reflection->setAccessible(true);
            $reflection->setValue($event, $hydrated);

            $result = $event->getOccurredAt(null);

            // Curacao is UTC-4. A wall-clock of 12:00 stored in the DB represents
            // 12:00 Curacao = 16:00 UTC. With local TZ = UTC, the getter must
            // return a DateTime whose UTC wall-clock is 16:00.
            $this->assertSame(
                '2026-04-27 16:00:00',
                $result->format('Y-m-d H:i:s'),
                'DATETIME getter must anchor the stored wall-clock to America/Curacao before converting to the local timezone'
            );
            $this->assertSame('UTC', $result->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * @return void
     */
    public function testDateTimeGetterIsIdempotentWhenLocalTimezoneIsCuracao()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Curacao');

            $event = new \CeleryTz\Event();
            $hydrated = new \DateTime('2026-04-27 12:00:00', new \DateTimeZone('America/Curacao'));
            $reflection = new \ReflectionProperty($event, 'occurred_at');
            $reflection->setAccessible(true);
            $reflection->setValue($event, $hydrated);

            $result = $event->getOccurredAt(null);

            $this->assertSame('2026-04-27 12:00:00', $result->format('Y-m-d H:i:s'));
            $this->assertSame('America/Curacao', $result->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * @return void
     */
    public function testDateGetterDoesNotConvertTimezone()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');

            $event = new \CeleryTz\Event();
            $hydrated = new \DateTime('2026-04-27', new \DateTimeZone('UTC'));
            $reflection = new \ReflectionProperty($event, 'event_date');
            $reflection->setAccessible(true);
            $reflection->setValue($event, $hydrated);

            $this->assertSame('2026-04-27', $event->getEventDate());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * @return void
     */
    public function testTimeGetterDoesNotConvertTimezone()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');

            $event = new \CeleryTz\Event();
            $hydrated = new \DateTime('1970-01-01 09:30:00', new \DateTimeZone('UTC'));
            $reflection = new \ReflectionProperty($event, 'event_time');
            $reflection->setAccessible(true);
            $reflection->setValue($event, $hydrated);

            $this->assertSame('09:30:00', $event->getEventTime());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * Locks in the generator-level fix so a future refactor cannot silently
     * regress to `clone $this->column; setTimeZone(local)` — a no-op when the
     * underlying DateTime already carries the runtime default timezone.
     *
     * @return void
     */
    public function testGeneratedDateTimeGetterAnchorsValueToCuracaoBeforeLocalConversion()
    {
        $column = new Column('occurred_at', 'TIMESTAMP');
        $behavior = new CeleryDateTimeBehavior();

        $generated = $behavior->getNewGetterContent($column);

        $this->assertStringContainsString(
            "new \\DateTime((\$this->occurred_at->format('Y-m-d H:i:s')), new \\DateTimeZone('America/Curacao'))",
            $generated,
            'DATETIME getter must reconstruct the value with an explicit America/Curacao anchor'
        );
        $this->assertStringContainsString(
            '$dt->setTimeZone(new \\DateTimeZone(date_default_timezone_get()))',
            $generated,
            'DATETIME getter must convert from Curacao to the local timezone'
        );
    }

    /**
     * Reproduces celery-payroll/web-app#4535: a naive string passed to a DATETIME
     * setter under a non-Curacao runtime timezone must be interpreted in that
     * runtime timezone first, then shifted to America/Curacao. Pre-fix, the
     * string was handed straight to PropelDateTime::newInstance with the Curacao
     * timezone and stored as-is — wrong by the local↔Curacao offset.
     *
     * @return void
     */
    public function testDateTimeSetterShiftsNaiveStringFromLocalTimezoneToCuracao()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');

            $event = new \CeleryTz\Event();
            $event->setOccurredAt('2026-04-28 10:00:00');

            $stored = $this->readRawOccurredAt($event);

            // 10:00 UTC → 06:00 America/Curacao (UTC-4, no DST).
            $this->assertSame(
                '2026-04-28 06:00:00',
                $stored->format('Y-m-d H:i:s'),
                'DATETIME setter must shift a naive string from the runtime timezone to America/Curacao'
            );
            $this->assertSame('America/Curacao', $stored->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * @return void
     */
    public function testDateTimeSetterShiftsNaiveStringFromAmsterdamToCuracao()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Amsterdam');

            $event = new \CeleryTz\Event();
            // Late April: Amsterdam is in CEST (UTC+2). 10:00 CEST = 04:00 Curacao.
            $event->setOccurredAt('2026-04-28 10:00:00');

            $stored = $this->readRawOccurredAt($event);

            $this->assertSame(
                '2026-04-28 04:00:00',
                $stored->format('Y-m-d H:i:s'),
                'DATETIME setter must honor the active runtime timezone, not assume Curacao'
            );
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * A string with an embedded offset is unambiguous. The runtime-timezone wrap
     * must NOT cause a second shift on top of the explicit offset.
     *
     * @return void
     */
    public function testDateTimeSetterRespectsExplicitOffsetWithoutDoubleShift()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');

            $event = new \CeleryTz\Event();
            // 10:00+02:00 = 08:00 UTC = 04:00 Curacao.
            $event->setOccurredAt('2026-04-28 10:00:00+02:00');

            $stored = $this->readRawOccurredAt($event);

            $this->assertSame(
                '2026-04-28 04:00:00',
                $stored->format('Y-m-d H:i:s'),
                'Embedded offset in the input string must not be double-shifted by the runtime-timezone wrap'
            );
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * Integer Unix timestamps are timezone-agnostic. PropelDateTime::newInstance
     * builds them directly in America/Curacao and ignores the supplied timezone,
     * so the runtime timezone must not affect the stored Curacao wall-clock.
     *
     * @return void
     */
    public function testDateTimeSetterAnchorsUnixTimestampToCuracaoRegardlessOfRuntimeTimezone()
    {
        $originalTz = date_default_timezone_get();
        try {
            // 1745834400 = 2025-04-28T10:00:00Z = 2025-04-28 06:00 Curacao.
            $expected = '2025-04-28 06:00:00';

            date_default_timezone_set('UTC');
            $eventUtc = new \CeleryTz\Event();
            $eventUtc->setOccurredAt(1745834400);

            date_default_timezone_set('Asia/Tokyo');
            $eventTokyo = new \CeleryTz\Event();
            $eventTokyo->setOccurredAt(1745834400);

            $this->assertSame($expected, $this->readRawOccurredAt($eventUtc)->format('Y-m-d H:i:s'));
            $this->assertSame($expected, $this->readRawOccurredAt($eventTokyo)->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * @return void
     */
    public function testDateTimeSetterStoresNullForEmptyStringAndNull()
    {
        $event = new \CeleryTz\Event();

        $event->setOccurredAt('');
        $this->assertNull($this->readRawOccurredAt($event), 'Empty string must persist as NULL');

        $event->setOccurredAt(null);
        $this->assertNull($this->readRawOccurredAt($event), 'null must persist as NULL');
    }

    /**
     * The wrap is gated on `!$v instanceof \DateTimeInterface`, so DateTime and
     * DateTimeImmutable inputs bypass it. They must still land in Curacao tz
     * with the correct instant, and the caller's instance must not be mutated.
     *
     * @return void
     */
    public function testDateTimeSetterAcceptsDateTimeAndDateTimeImmutableWithoutMutation()
    {
        $originalTz = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');

            $mutableInput = new \DateTime('2026-04-28 10:00:00', new \DateTimeZone('UTC'));
            $immutableInput = new \DateTimeImmutable('2026-04-28 10:00:00', new \DateTimeZone('UTC'));

            $eventA = new \CeleryTz\Event();
            $eventA->setOccurredAt($mutableInput);

            $eventB = new \CeleryTz\Event();
            $eventB->setOccurredAt($immutableInput);

            // 10:00 UTC = 06:00 Curacao for both inputs.
            $this->assertSame('2026-04-28 06:00:00', $this->readRawOccurredAt($eventA)->format('Y-m-d H:i:s'));
            $this->assertSame('2026-04-28 06:00:00', $this->readRawOccurredAt($eventB)->format('Y-m-d H:i:s'));

            // Caller's mutable DateTime must not be mutated by the setter.
            $this->assertSame('2026-04-28 10:00:00', $mutableInput->format('Y-m-d H:i:s'));
            $this->assertSame('UTC', $mutableInput->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    /**
     * The runtime-timezone wrap is meaningful only for DATETIME/TIMESTAMP. DATE
     * and TIME columns are timezone-agnostic; if the wrap leaks into their
     * generated setters, naive strings would be silently date-shifted across
     * boundaries depending on the runtime timezone.
     *
     * @return void
     */
    public function testGeneratedDateAndTimeSettersDoNotWrapInputInRuntimeTimezone()
    {
        $behavior = new CeleryDateTimeBehavior();

        $generatedDate = $behavior->getNewSetterContent($this->getColumnFromGeneratorSchema('event_date'));
        $generatedTime = $behavior->getNewSetterContent($this->getColumnFromGeneratorSchema('event_time'));

        $this->assertStringNotContainsString(
            'date_default_timezone_get()',
            $generatedDate,
            'DATE setter must remain timezone-agnostic'
        );
        $this->assertStringNotContainsString(
            'date_default_timezone_get()',
            $generatedTime,
            'TIME setter must remain timezone-agnostic'
        );
    }

    /**
     * Mirror of the existing getter-side regression lock: ensure the DATETIME
     * setter explicitly anchors non-DateTimeInterface input to the runtime
     * timezone before letting the surrounding code shift it to Curacao.
     *
     * @return void
     */
    public function testGeneratedDateTimeSetterAnchorsNonDateTimeInputToRuntimeTimezone()
    {
        $behavior = new CeleryDateTimeBehavior();

        $generated = $behavior->getNewSetterContent($this->getColumnFromGeneratorSchema('occurred_at'));

        $this->assertStringContainsString(
            'if (!$v instanceof \DateTimeInterface)',
            $generated,
            'DATETIME setter must guard the wrap behind a DateTimeInterface check'
        );
        $this->assertStringContainsString(
            "PropelDateTime::newInstance(\$v, new \\DateTimeZone(date_default_timezone_get()), 'DateTime')",
            $generated,
            'DATETIME setter must wrap non-DateTimeInterface input in the runtime timezone before the Curacao conversion'
        );
    }

    /**
     * @return \DateTimeInterface|null
     */
    private function readRawOccurredAt(\CeleryTz\Event $event)
    {
        $reflection = new \ReflectionProperty($event, 'occurred_at');
        $reflection->setAccessible(true);

        return $reflection->getValue($event);
    }

    /**
     * Returns a Column attached to its parent table so generator helpers like
     * Column::getFQConstantName() resolve correctly.
     *
     * @param string $columnName
     * @return Column
     */
    private function getColumnFromGeneratorSchema(string $columnName): Column
    {
        static $database = null;

        if ($database === null) {
            $schema = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<database name="celery_tz_gen" defaultIdMethod="native" namespace="CeleryTzGen">
    <table name="event">
        <column name="id" type="integer" required="true" primaryKey="true" autoIncrement="true"/>
        <column name="occurred_at" type="TIMESTAMP"/>
        <column name="event_date" type="DATE"/>
        <column name="event_time" type="TIME"/>
        <behavior name="celery_date_time"/>
    </table>
</database>
XML;
            $builder = new QuickBuilder();
            $builder->setSchema($schema);
            /** @var Database $database */
            $database = $builder->getDatabase();
        }

        return $database->getTable('event')->getColumn($columnName);
    }
}
