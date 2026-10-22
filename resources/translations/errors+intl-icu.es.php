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
    'Platform text options must map non-empty engine names to arrays.' =>
        'Las opciones de texto de plataforma deben asociar nombres de motor no vacíos con arreglos.',
    'Unsupported platform text option "{option}".' =>
        'Opción de texto de plataforma no admitida: "{option}".',
    'Platform text option "{field}" must be a non-empty string or null.' =>
        'La opción de texto de plataforma "{field}" debe ser un string no vacío o null.',
    'Source and destination schemas are incompatible for table "{table}".' =>
        'Los esquemas de origen y destino son incompatibles para la tabla "{table}".',
    'Rules file must exist and be readable: {file}' =>
        'El archivo de reglas debe existir y ser legible: {file}',
    'Could not load rules file "{file}": {error}.' =>
        'No se pudo cargar el archivo de reglas "{file}": {error}.',
    'Rules file must return an array; {type} given.' =>
        'El archivo de reglas debe devolver un arreglo; se recibió {type}.',
    'Rule "{rule}" must implement DataRulesInterface or be callable; {type} given.' =>
        'La regla "{rule}" debe implementar DataRulesInterface o ser invocable; se recibió {type}.',
    'Table name must be non-empty and different from the schema sheet name: "{table}".' =>
        'El nombre de tabla no debe estar vacío ni coincidir con el nombre de la hoja de esquema: "{table}".',
    'Duplicate primary key in table "{table}".' =>
        'Llave primaria duplicada en la tabla "{table}".',
    'Primary key column "{column}" in table "{table}" must be present and non-null.' =>
        'La columna de llave primaria "{column}" de la tabla "{table}" debe estar presente y no ser null.',
    'Option "{option}" must be a boolean; {type} given.' =>
        'La opción "{option}" debe ser un booleano; se recibió {type}.',
    // Pipeline.
    'Table names must not be empty.' =>
        'Los nombres de tablas no deben estar vacíos.',
    'Source is not set in the ETL pipeline. Use the extract() method to set the source.' =>
        'La fuente no está definida en el pipeline ETL. Usa el método extract() para definirla.',
    'Rules are not set in the ETL pipeline. Use the transform() method to set the rules.' =>
        'Las reglas no están definidas en el pipeline ETL. Usa el método transform() para definirlas.',
    'Target is not set in the ETL pipeline. Use the load() method to set the target.' =>
        'El destino no está definido en el pipeline ETL. Usa el método load() para definirlo.',

    // Databases.
    'Table "{table}" not found in spreadsheet.' =>
        'No se encontró la tabla "{table}" en la planilla.',
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

    'Option "{option}" must be a string; {type} given.' =>
        'La opción "{option}" debe ser un string; se recibió {type}.',
    'Option "{option}" must be a Doctrine connection, an array or a string; {type} given.' =>
        'La opción "{option}" debe ser una conexión Doctrine, un arreglo o un string; se recibió {type}.',
    'Option "{option}" must be a SpreadsheetInterface instance; {type} given.' =>
        'La opción "{option}" debe ser una instancia de SpreadsheetInterface; se recibió {type}.',
    'Argument "{argument}" must be a string; {type} given.' =>
        'El argumento "{argument}" debe ser un string; se recibió {type}.',

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
    'Schema field "{field}" must be a string; {type} given.' =>
        'El campo del esquema "{field}" debe ser un string; se recibió {type}.',
    'Schema field "{field}" must be an integer; {type} given.' =>
        'El campo del esquema "{field}" debe ser un entero; se recibió {type}.',
    'Schema field "{field}" must be a boolean; {type} given.' =>
        'El campo del esquema "{field}" debe ser un booleano; se recibió {type}.',
    'Schema field "{field}" must be an array; {type} given.' =>
        'El campo del esquema "{field}" debe ser un arreglo; se recibió {type}.',
    'Default value for column "{column}" must be a string, a finite number, a boolean or Stringable; {type} given.' =>
        'El valor predeterminado de la columna "{column}" debe ser un string, un número finito, un booleano o Stringable; se recibió {type}.',
    'Could not encode option value as JSON: {error}.' =>
        'No se pudo codificar el valor de la opción como JSON: {error}.',
    'Unsupported option value type: {type}.' =>
        'Tipo de valor de opción no soportado: {type}.',
    'Rows for table "{table}" must be an array; {type} given.' =>
        'Las filas de la tabla "{table}" deben ser un arreglo; se recibió {type}.',
    'Every row in table "{table}" must be a non-empty array.' =>
        'Cada fila de la tabla "{table}" debe ser un arreglo no vacío.',
    'Every row in table "{table}" must have the same columns.' =>
        'Cada fila de la tabla "{table}" debe tener las mismas columnas.',
    'Affected row count cannot be represented as a PHP integer.' =>
        'La cantidad de filas afectadas no se puede representar como un entero de PHP.',
    'Table "{table}" must have a primary key for PostgreSQL upserts.' =>
        'La tabla "{table}" debe tener una llave primaria para los upserts de PostgreSQL.',
    'Doctrine defaultTableOptions must have string keys.' =>
        'Las opciones defaultTableOptions de Doctrine deben tener claves string.',
    'Unknown Doctrine driver.' =>
        'Driver de Doctrine desconocido.',
    'Doctrine driverClass must implement Doctrine DBAL Driver.' =>
        'La opción driverClass de Doctrine debe implementar Doctrine DBAL Driver.',
    'Doctrine wrapperClass must extend Doctrine DBAL Connection.' =>
        'La opción wrapperClass de Doctrine debe extender Doctrine DBAL Connection.',
    'Index name must not be empty.' =>
        'El nombre del índice no debe estar vacío.',
    'Schema column names must be non-empty strings.' =>
        'Los nombres de columnas del esquema deben ser strings no vacíos.',
    'Doctrine option "{option}" must be a string; {type} given.' =>
        'La opción "{option}" de Doctrine debe ser un string; se recibió {type}.',
    'Doctrine option "{option}" must be a boolean; {type} given.' =>
        'La opción "{option}" de Doctrine debe ser un booleano; se recibió {type}.',
    'Doctrine option "{option}" must be an integer; {type} given.' =>
        'La opción "{option}" de Doctrine debe ser un entero; se recibió {type}.',
    'Doctrine option "{option}" must be an array; {type} given.' =>
        'La opción "{option}" de Doctrine debe ser un arreglo; se recibió {type}.',
    'PostgreSQL table names must not be empty.' =>
        'Los nombres de tablas de PostgreSQL no deben estar vacíos.',
];
