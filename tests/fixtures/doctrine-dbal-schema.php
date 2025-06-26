<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

// Create a new schema instance.
$schema = new Schema();

// Create the party table (customers and suppliers).
$partyTable = $schema->createTable('party');
$partyTable->addColumn('id', Types::GUID, ['notnull' => true]);
$partyTable->addColumn('name', Types::STRING, ['length' => 255]);
$partyTable->addColumn('tax_id', Types::STRING, ['length' => 50]);
$partyTable->addColumn('country_code', Types::STRING, ['length' => 2]);
$partyTable->setPrimaryKey(['id']);
$partyTable->addIndex(['tax_id', 'country_code'], 'idx_party_tax_country');

// Create the item table (products or services).
$itemTable = $schema->createTable('item');
$itemTable->addColumn('id', Types::GUID, ['notnull' => true]);
$itemTable->addColumn('name', Types::STRING, ['length' => 255]);
$itemTable->addColumn('description', Types::TEXT, ['notnull' => false]);
$itemTable->addColumn('unit_price', Types::DECIMAL, ['precision' => 19, 'scale' => 4]);
$itemTable->addColumn('parent_item_id', Types::GUID, ['notnull' => false]);
$itemTable->setPrimaryKey(['id']);
$itemTable->addForeignKeyConstraint('item', ['parent_item_id'], ['id'], [
    'onDelete' => 'SET NULL',
    'onUpdate' => 'CASCADE',
], 'fk_item_parent');
$itemTable->addIndex(['name'], 'idx_item_name');

// Create the tax_scheme table (VAT, GST, etc.).
$taxSchemeTable = $schema->createTable('tax_scheme');
$taxSchemeTable->addColumn('id', Types::GUID, ['notnull' => true]);
$taxSchemeTable->addColumn('code', Types::STRING, ['length' => 20]);
$taxSchemeTable->addColumn('name', Types::STRING, ['length' => 100]);
$taxSchemeTable->setPrimaryKey(['id']);
$taxSchemeTable->addUniqueIndex(['code'], 'unq_tax_scheme_code');

// Create the tax_category table (standard rate, reduced rate, etc.).
$taxCategoryTable = $schema->createTable('tax_category');
$taxCategoryTable->addColumn('id', Types::GUID, ['notnull' => true]);
$taxCategoryTable->addColumn('name', Types::STRING, ['length' => 100]);
$taxCategoryTable->addColumn('tax_scheme_id', Types::GUID, ['notnull' => true]);
$taxCategoryTable->addColumn('rate', Types::DECIMAL, ['precision' => 5, 'scale' => 2]);
$taxCategoryTable->setPrimaryKey(['id']);
$taxCategoryTable->addForeignKeyConstraint('tax_scheme', ['tax_scheme_id'], ['id'], [
    'onDelete' => 'RESTRICT',
    'onUpdate' => 'CASCADE',
], 'fk_tax_category_scheme');
$taxCategoryTable->addIndex(['tax_scheme_id'], 'idx_tax_category_scheme');

// Create the invoice table (main document).
$invoiceTable = $schema->createTable('invoice');
$invoiceTable->addColumn('id', Types::GUID, ['notnull' => true]);
$invoiceTable->addColumn('invoice_number', Types::STRING, ['length' => 50]);
$invoiceTable->addColumn('billing_period', Types::INTEGER, ['notnull' => false]);
$invoiceTable->addColumn('issue_date', Types::DATE_MUTABLE);
$invoiceTable->addColumn('invoice_type_code', Types::STRING, ['length' => 10]);
$invoiceTable->addColumn('currency_code', Types::STRING, ['length' => 3]);
$invoiceTable->addColumn('customer_id', Types::GUID, ['notnull' => true]);
$invoiceTable->addColumn('supplier_id', Types::GUID, ['notnull' => true]);
$invoiceTable->addColumn('total_amount', Types::DECIMAL, ['precision' => 19, 'scale' => 4]);
$invoiceTable->setPrimaryKey(['id']);
$invoiceTable->addForeignKeyConstraint('party', ['customer_id'], ['id'], [
    'onDelete' => 'RESTRICT',
    'onUpdate' => 'CASCADE',
], 'fk_invoice_customer');
$invoiceTable->addForeignKeyConstraint('party', ['supplier_id'], ['id'], [
    'onDelete' => 'RESTRICT',
    'onUpdate' => 'CASCADE',
], 'fk_invoice_supplier');
$invoiceTable->addUniqueIndex(['invoice_number'], 'unq_invoice_number');
$invoiceTable->addIndex(['issue_date'], 'idx_invoice_date');
$invoiceTable->addIndex(['customer_id'], 'idx_invoice_customer');
$invoiceTable->addIndex(['supplier_id'], 'idx_invoice_supplier');

// Create the invoice_line table (line items in an invoice).
$invoiceLineTable = $schema->createTable('invoice_line');
$invoiceLineTable->addColumn('id', Types::GUID, ['notnull' => true]);
$invoiceLineTable->addColumn('invoice_id', Types::GUID, ['notnull' => true]);
$invoiceLineTable->addColumn('item_id', Types::GUID, ['notnull' => true]);
$invoiceLineTable->addColumn('quantity', Types::DECIMAL, ['precision' => 12, 'scale' => 4]);
$invoiceLineTable->addColumn('unit_price', Types::DECIMAL, ['precision' => 19, 'scale' => 4]);
$invoiceLineTable->addColumn('total_price', Types::DECIMAL, ['precision' => 19, 'scale' => 4]);
$invoiceLineTable->setPrimaryKey(['id']);
$invoiceLineTable->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], [
    'onDelete' => 'CASCADE',
    'onUpdate' => 'CASCADE',
], 'fk_invoice_line_invoice');
$invoiceLineTable->addForeignKeyConstraint('item', ['item_id'], ['id'], [
    'onDelete' => 'RESTRICT',
    'onUpdate' => 'CASCADE',
], 'fk_invoice_line_item');
$invoiceLineTable->addIndex(['invoice_id'], 'idx_invoice_line_invoice');
$invoiceLineTable->addIndex(['item_id'], 'idx_invoice_line_item');

// Create the invoice_line_tax table (taxes applied to invoice lines).
$invoiceLineTaxTable = $schema->createTable('invoice_line_tax');
$invoiceLineTaxTable->addColumn('id', Types::GUID, ['notnull' => true]);
$invoiceLineTaxTable->addColumn('invoice_line_id', Types::GUID, ['notnull' => true]);
$invoiceLineTaxTable->addColumn('tax_category_id', Types::GUID, ['notnull' => true]);
$invoiceLineTaxTable->addColumn('tax_amount', Types::DECIMAL, ['precision' => 19, 'scale' => 4]);
$invoiceLineTaxTable->setPrimaryKey(['id']);
$invoiceLineTaxTable->addForeignKeyConstraint('invoice_line', ['invoice_line_id'], ['id'], [
    'onDelete' => 'CASCADE',
    'onUpdate' => 'CASCADE',
], 'fk_invoice_line_tax_line');
$invoiceLineTaxTable->addForeignKeyConstraint('tax_category', ['tax_category_id'], ['id'], [
    'onDelete' => 'RESTRICT',
    'onUpdate' => 'CASCADE',
], 'fk_invoice_line_tax_category');
$invoiceLineTaxTable->addIndex(['invoice_line_id'], 'idx_invoice_line_tax_line');
$invoiceLineTaxTable->addIndex(['tax_category_id'], 'idx_invoice_line_tax_category');

// Return the schema.
return $schema;
