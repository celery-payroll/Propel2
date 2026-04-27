<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Behavior\CeleryDateTime;

use Propel\Generator\Behavior\CeleryDateTime\CeleryDateTimeBehavior;
use Propel\Generator\Model\Column;
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
}
