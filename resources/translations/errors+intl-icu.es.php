<?php

declare(strict_types=1);

/**
 * Derafu: ETL - From Spreadsheets to Databases Seamlessly.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

return [
    // Pipeline.
    'Source is not set in the ETL pipeline. Use the extract() method to set the source.' =>
        'La fuente no está definida en el pipeline ETL. Usa el método extract() para definirla.',
    'Rules are not set in the ETL pipeline. Use the transform() method to set the rules.' =>
        'Las reglas no están definidas en el pipeline ETL. Usa el método transform() para definirlas.',
    'Target is not set in the ETL pipeline. Use the load() method to set the target.' =>
        'El destino no está definido en el pipeline ETL. Usa el método load() para definirlo.',

    // Databases.
    'The source is not valid for creating a connection to a database' =>
        'La fuente no es válida para crear una conexión a una base de datos',
    'Format is required for data connection.' =>
        'El formato es obligatorio para una conexión de datos.',
    'Format is required for loading from dump in a spreadsheet database.' =>
        'El formato es obligatorio para cargar desde un volcado en una base de datos de planilla.',
    'File does not exist and creation is disabled for this file: {file}' =>
        'El archivo no existe y la creación está deshabilitada para este archivo: {file}',
    'Target directory does not exist and cannot be created: {file}' =>
        'El directorio de destino no existe y no se puede crear: {file}',
    'Directory {directory} is not writable for creating an empty file: {file}' =>
        'El directorio {directory} no tiene permisos de escritura para crear un archivo vacío: {file}',
    'File is not readable: {file}' =>
        'El archivo no se puede leer: {file}',
    'File is not writable: {file}' =>
        'El archivo no se puede escribir: {file}',
    'Dump from Doctrine DBAL not implemented.' =>
        'El volcado desde Doctrine DBAL no está implementado.',
    'Unsupported database platform: {platform}.' =>
        'Plataforma de base de datos no soportada: {platform}.',

    // Schemas.
    'Foreign key must have local and foreign columns.' =>
        'La llave foránea debe tener columnas locales y foráneas.',
    'Index must have columns.' =>
        'El índice debe tener columnas.',
    '$spreadsheet must be an instance of SpreadsheetInterface.' =>
        '$spreadsheet debe ser una instancia de SpreadsheetInterface.',
    'The Doctrine DBAL Schema must be an instance of DoctrineSchema.' =>
        'El esquema de Doctrine DBAL debe ser una instancia de DoctrineSchema.',
    'Invalid column name format: "{name}". Must be in the format "table.column".' =>
        'Formato de nombre de columna inválido: "{name}". Debe tener el formato "tabla.columna".',
    'Invalid index name format: "{name}". Must be in the format "table.index".' =>
        'Formato de nombre de índice inválido: "{name}". Debe tener el formato "tabla.indice".',
    'Invalid foreign key name format: "{name}". Must be in the format "table.foreign_key".' =>
        'Formato de nombre de llave foránea inválido: "{name}". Debe tener el formato "tabla.llave_foranea".',
    'Table "{table}" not found in schema sheet.' =>
        'No se encontró la tabla "{table}" en la hoja del esquema.',
    'Missing columns for index "{index}" in table "{table}".' =>
        'Faltan columnas para el índice "{index}" en la tabla "{table}".',
    'Invalid type "{type}" for index "{index}" in table "{table}".' =>
        'Tipo "{type}" no válido para el índice "{index}" en la tabla "{table}".',
    'Unsupported flag "{flag}" for index "{index}" in table "{table}".' =>
        'Flag "{flag}" no soportado para el índice "{index}" en la tabla "{table}".',
    'Missing required properties for foreign key "{foreign_key}" in table "{table}".' =>
        'Faltan propiedades obligatorias para la llave foránea "{foreign_key}" en la tabla "{table}".',
];
