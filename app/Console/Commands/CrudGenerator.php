<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class CrudGenerator extends Command
{
    protected $signature = 'app:crud-generator';
    protected $description = 'Generate CRUD operations with relationships';

    protected $columnTypes = [
        1 => 'string',
        2 => 'integer',
        3 => 'boolean',
        4 => 'foreignId',
        5 => 'text',
        6 => 'date',
        7 => 'datetime',
        8 => 'decimal',
    ];

    protected $relationshipTypes = [
        1 => 'belongsTo',
        2 => 'hasMany',
        3 => 'hasOne',
        4 => 'belongsToMany',
    ];

    protected function getStub($type)
    {
        return file_get_contents(resource_path("stubs/$type.stub"));
    }

    protected function generateResource($name, $columns)
    {
        $resourceFile = app_path("Http/Resources/{$name}Resource.php");

        $mappings = array_map(function($column) {
            return "            '$column' => \$this->$column,";
        }, $columns);

        $resourceMappings = implode("\n", $mappings);

        $stub = str_replace(
            [
                '{{modelName}}',
                '{{resourceMappings}}'
            ],
            [
                $name,
                $resourceMappings
            ],
            $this->getStub('resource')
        );

        File::put($resourceFile, $stub);
    }

    protected function generateMigration($name, $columns, $foreignKeys)
    {
        $tableName = Str::plural(Str::snake($name));
        $migrationName = 'create_' . $tableName . '_table';
        $migrationFile = database_path('migrations/' . date('Y_m_d_His') . '_' . $migrationName . '.php');

        $stub = str_replace(
            ['{{tableName}}', '{{tableColumns}}', '{{foreignKeys}}'],
            [$tableName, $columns, $foreignKeys],
            $this->getStub('migration')
        );

        File::put($migrationFile, $stub);
    }

    protected function generateModel($name, $fillable, $relationships)
    {
        $modelFile = app_path("Models/{$name}.php");

        $stub = str_replace(
            ['{{modelName}}', '{{fillable}}', '{{relationships}}'],
            [$name, $fillable, $relationships],
            $this->getStub('model')
        );

        File::put($modelFile, $stub);
    }

    protected function generateController($name, $validationRules)
    {
        $controllerFile = app_path("Http/Controllers/{$name}Controller.php");

        $stub = str_replace(
            [
                '{{modelName}}',
                '{{modelVariable}}',
                '{{validationRules}}'
            ],
            [
                $name,
                Str::camel($name),
                $validationRules
            ],
            $this->getStub('controller')
        );

        File::put($controllerFile, $stub);
    }

    protected function processColumn($column)
    {
        $this->info("Available column types:");
        foreach ($this->columnTypes as $key => $type) {
            $this->line("$key: $type");
        }

        $columnType = $this->ask("Enter column type number for '$column'");
        $length = null;
        $nullable = $this->confirm("Is '$column' nullable?");

        $columnDefinition = "\$table->" . $this->columnTypes[$columnType] . "('$column')";

        if ($this->columnTypes[$columnType] === 'foreignId') {
            $reference = $this->ask("Enter the referenced table name");
            return [
                'column' => $columnDefinition . ($nullable ? '->nullable()' : '') . ";\n",
                'foreign' => "\$table->foreign('$column')->references('id')->on('$reference')->onDelete('cascade');\n"
            ];
        }

        return [
            'column' => $columnDefinition . ($nullable ? '->nullable()' : '') . ";\n",
            'foreign' => ''
        ];
    }

    protected function processRelationship($name)
    {
        $relationships = [];

        while ($this->confirm('Do you want to add a relationship?')) {
            $this->info("Available relationship types:");
            foreach ($this->relationshipTypes as $key => $type) {
                $this->line("$key: $type");
            }

            $relationType = $this->ask("Enter relationship type number");
            $relatedModel = $this->ask("Enter related model name");

            $method = Str::camel($relatedModel);
            if (in_array($this->relationshipTypes[$relationType], ['hasMany', 'belongsToMany'])) {
                $method = Str::plural($method);
            }

            $relationships[] = "
    public function $method()
    {
        return \$this->{$this->relationshipTypes[$relationType]}({$relatedModel}::class);
    }";
        }

        return implode("\n", $relationships);
    }

    public function handle()
    {
        $name = $this->ask('What is the name of the model?');
        $columnsArray = [];

        $tableColumns = '';
        $foreignKeys = '';
        $fillable = [];
        $validationRules = [];

        do {
            $column = $this->ask('Enter column name');
            $columnsArray[] = $column;

            $columnData = $this->processColumn($column);
            $tableColumns .= $columnData['column'];
            $foreignKeys .= $columnData['foreign'];
            $fillable[] = "'$column'";
            $validationRules[] = "'$column' => 'required'";

        } while ($this->confirm('Do you want to add another column?', true));

        $relationships = $this->processRelationship($name);
        $fillableString = implode(",\n        ", $fillable);
        $validationRulesString = implode(",\n            ", $validationRules);

        $this->generateMigration($name, $tableColumns, $foreignKeys);
        $this->generateModel($name, $fillableString, $relationships);
        $this->generateController($name, $validationRulesString);
        $this->generateResource($name, $columnsArray);

        $this->info('CRUD for ' . $name . ' generated successfully.');
    }
}
