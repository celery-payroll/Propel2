<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Behavior\CeleryColumnLength;

use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\Exception\ColumnValueTooLongException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Util\ColumnLengthValidator;
use Propel\Tests\TestCase;

/**
 * Tests for CeleryColumnLengthBehavior, ColumnLengthValidator and ColumnValueTooLongException.
 *
 * @group model
 */
class CeleryColumnLengthBehaviorTest extends TestCase
{
    /**
     * Build the generated classes against an in-memory SQLite database so the injected
     * validation can be exercised through the real save() path.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        if (class_exists('\CeleryLength\Item')) {
            return;
        }

        $builder = new QuickBuilder();
        $builder->setSchema(static::getRuntimeSchema());
        $builder->build();
    }

    /**
     * A table covering every branch: sized text columns, a LOB, an unsized text column,
     * a non-text column and an excluded column.
     *
     * @return string
     */
    protected static function getRuntimeSchema(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<database name="celery_length" defaultIdMethod="native" namespace="CeleryLength">
    <table name="item">
        <column name="id" type="integer" required="true" primaryKey="true" autoIncrement="true"/>
        <column name="code" type="VARCHAR" size="3"/>
        <column name="label" type="VARCHAR" size="10"/>
        <column name="skipped" type="VARCHAR" size="2"/>
        <column name="payload" type="BLOB"/>
        <column name="notes" type="LONGVARCHAR"/>
        <column name="quantity" type="INTEGER"/>
        <behavior name="celery_column_length">
            <parameter name="exclude_columns" value="skipped"/>
        </behavior>
    </table>
</database>
XML;
    }

    /**
     * Generated source for the schema above, without building or evaluating the classes.
     *
     * @return string
     */
    protected function getGeneratedSource(): string
    {
        static $source = null;

        if ($source === null) {
            $builder = new QuickBuilder();
            $builder->setSchema(str_replace(
                ['celery_length', 'CeleryLength'],
                ['celery_length_gen', 'CeleryLengthGen'],
                static::getRuntimeSchema()
            ));
            $source = $builder->getClasses();
        }

        return $source;
    }

    /**
     * The argument list of the injected validator call.
     *
     * Assertions must be scoped to this block rather than the whole generated source: the
     * TableMap separately emits `addColumn('skipped', 'Skipped', 'VARCHAR', ...)` for every
     * column, so a negative assertion against the full source matches the TableMap and can
     * never fail, whatever the behavior does.
     *
     * @param string|null $source
     * @return string
     */
    protected function getInjectedValidatorBlock(?string $source = null): string
    {
        $source = $source ?? $this->getGeneratedSource();

        if (!preg_match('/ColumnLengthValidator::validate\((.*?)\n\s*\);/s', $source, $matches)) {
            return '';
        }

        return $matches[1];
    }

    /**
     * @return void
     */
    public function testOverLongValueThrowsWithFullMetadata()
    {
        $item = new \CeleryLength\Item();
        $item->setCode('EURO');

        try {
            $item->save();
            $this->fail('Saving an over-long value must throw ColumnValueTooLongException');
        } catch (ColumnValueTooLongException $e) {
            $this->assertSame('item', $e->getTableName());
            $this->assertSame('code', $e->getColumnName());
            $this->assertSame('Code', $e->getPhpName());
            $this->assertSame(3, $e->getMaxLength());
            $this->assertSame(4, $e->getActualLength());
            $this->assertSame(
                "The value for 'item.code' is too long: 4 characters given, 3 allowed.",
                $e->getMessage()
            );
        }
    }

    /**
     * Existing application code catches PropelException around save(); the new exception
     * must stay inside that hierarchy or those handlers would stop working.
     *
     * @return void
     */
    public function testExceptionIsCatchableAsPropelException()
    {
        $item = new \CeleryLength\Item();
        $item->setCode('EURO');

        $this->expectException(PropelException::class);

        $item->save();
    }

    /**
     * @return void
     */
    public function testValueAtExactlyTheLimitIsAccepted()
    {
        $item = new \CeleryLength\Item();
        $item->setCode('EUR');
        $item->setLabel('0123456789');

        $item->save();

        $this->assertNotNull($item->getId(), 'A value at exactly the column length must save');
    }

    /**
     * A VARCHAR size counts characters, not bytes. 'éàü' is 6 bytes but 3 characters and
     * must fit a VARCHAR(3); a byte-based check would wrongly reject it.
     *
     * @return void
     */
    public function testMultiByteValueIsMeasuredInCharactersNotBytes()
    {
        $item = new \CeleryLength\Item();
        $item->setCode('éàü');

        $item->save();

        $this->assertNotNull($item->getId(), 'Three multi-byte characters must fit a VARCHAR(3)');
    }

    /**
     * @return void
     */
    public function testMultiByteValueOverTheLimitStillThrowsWithCharacterCount()
    {
        $item = new \CeleryLength\Item();
        $item->setCode('éàüö');

        try {
            $item->save();
            $this->fail('Four multi-byte characters must not fit a VARCHAR(3)');
        } catch (ColumnValueTooLongException $e) {
            $this->assertSame(4, $e->getActualLength(), 'Length must be reported in characters');
        }
    }

    /**
     * An update must validate the column being written but leave untouched columns alone.
     *
     * @return void
     */
    public function testUpdateValidatesModifiedColumnOnly()
    {
        $item = new \CeleryLength\Item();
        $item->setCode('EUR');
        $item->save();

        $item->setLabel('short');
        $item->save();

        $this->assertSame('short', $item->getLabel(), 'Updating an unrelated column must succeed');

        $item->setLabel('far too long to fit');

        $this->expectException(ColumnValueTooLongException::class);

        $item->save();
    }

    /**
     * The validator is driven by the modifiedColumns map, so a value that was never
     * assigned must not be checked. This is what keeps rows saveable after a migration
     * shrinks a column: the stale value is only rejected if the caller writes to it.
     *
     * @return void
     */
    public function testUnmodifiedColumnIsSkipped()
    {
        ColumnLengthValidator::validate('item', [], [
            ['ITEM.CODE', 'code', 'Code', 3, 'EURO'],
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testNullValueIsSkipped()
    {
        ColumnLengthValidator::validate('item', ['ITEM.CODE' => true], [
            ['ITEM.CODE', 'code', 'Code', 3, null],
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * @return void
     */
    public function testValidatorReportsTheFirstOffendingColumn()
    {
        $this->expectException(ColumnValueTooLongException::class);
        $this->expectExceptionMessage("The value for 'item.label' is too long: 19 characters given, 10 allowed.");

        ColumnLengthValidator::validate('item', ['ITEM.CODE' => true, 'ITEM.LABEL' => true], [
            ['ITEM.CODE', 'code', 'Code', 3, 'EUR'],
            ['ITEM.LABEL', 'label', 'Label', 10, 'far too long to fit'],
        ]);
    }

    /**
     * @return void
     */
    public function testGeneratedCodeChecksSizedTextColumns()
    {
        $block = $this->getInjectedValidatorBlock();

        $this->assertNotSame('', $block, 'The behavior must inject a validator call');
        $this->assertStringContainsString("'code', 'Code', 3", $block);
        $this->assertStringContainsString("'label', 'Label', 10", $block);
    }

    /**
     * The generated code lives in the model's own namespace, so a relative reference
     * would resolve against that namespace instead of the root.
     *
     * @return void
     */
    public function testGeneratedCodeUsesFullyQualifiedValidatorName()
    {
        $this->assertStringContainsString(
            '\Propel\Runtime\Util\ColumnLengthValidator::validate(',
            $this->getGeneratedSource(),
            'The emitted validator reference must be fully qualified'
        );
    }

    /**
     * @return void
     */
    public function testGeneratedCodePassesModifiedColumnsMap()
    {
        $this->assertStringContainsString('$this->modifiedColumns', $this->getInjectedValidatorBlock());
    }

    /**
     * A BLOB size does not describe a character limit, an unsized text column has no
     * limit to check, and a non-text column cannot overflow a length.
     *
     * @return void
     */
    public function testGeneratedCodeSkipsLobUnsizedAndNonTextColumns()
    {
        $block = $this->getInjectedValidatorBlock();

        $this->assertNotSame('', $block, 'The behavior must inject a validator call');
        $this->assertStringNotContainsString("'payload', 'Payload'", $block, 'LOB columns must not be checked');
        $this->assertStringNotContainsString("'notes', 'Notes'", $block, 'Unsized text columns must not be checked');
        $this->assertStringNotContainsString("'quantity', 'Quantity'", $block, 'Non-text columns must not be checked');
    }

    /**
     * @return void
     */
    public function testGeneratedCodeHonoursExcludeColumns()
    {
        $block = $this->getInjectedValidatorBlock();

        $this->assertNotSame('', $block, 'The behavior must inject a validator call');
        $this->assertStringNotContainsString(
            "'skipped', 'Skipped'",
            $block,
            'Columns listed in exclude_columns must not be checked'
        );
    }

    /**
     * A table whose only text columns are excluded, LOB or unsized must get no call at
     * all, rather than an empty validate() invocation.
     *
     * @return void
     */
    public function testTableWithoutValidatableColumnsGetsNoCall()
    {
        $builder = new QuickBuilder();
        $builder->setSchema(<<<XML
<?xml version="1.0" encoding="utf-8"?>
<database name="celery_length_empty" defaultIdMethod="native" namespace="CeleryLengthEmpty">
    <table name="plain">
        <column name="id" type="integer" required="true" primaryKey="true" autoIncrement="true"/>
        <column name="quantity" type="INTEGER"/>
        <column name="notes" type="LONGVARCHAR"/>
        <behavior name="celery_column_length"/>
    </table>
</database>
XML);

        $this->assertStringNotContainsString(
            'ColumnLengthValidator::validate(',
            $builder->getClasses(),
            'A table with no validatable columns must not emit a validator call'
        );
    }
}
