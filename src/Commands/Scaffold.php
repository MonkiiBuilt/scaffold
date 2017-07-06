<?php

namespace MonkiiBuilt\Scaffold\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;

/**
 * ##### Function flow: #####
 *
 * makeMigrations
 *   makeMigration
 *     makeColumns
 *       checkForRelationships
 *         makeColumn
 * makeRelationships
 *   makeMigrationRelationship
 * makeModels
 *   makeModelRelationships
 *
 * Class Scaffold
 */
class Scaffold extends Command
{
    private $data = [];
    private $relationships = [];
    private $migrations = [];
    private $models = [];
    private $softDeleteTables = [];
    private $fillable = [];
    private $fields = [];
    private $createMigrations = TRUE;
    private $createModels = TRUE;
    private $createControllers = TRUE;
    private $modelNamespace = 'App';
    private $controllerNamespace = 'App\Http\Controllers';

    private $blueprint;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scaffold:make {--verbose}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate migrations, models and controllers';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        $this->blueprint = new Blueprint(null);
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $data_file = app_path('scaffold.php');

        if(!file_exists($data_file)) {
            $this->error('This script expects the file ' . $data_file . ' to exist but it is missing');

            // If possible create sample file for user.
            $source = __DIR__ . DIRECTORY_SEPARATOR . 'exampleScaffold.php';
            if(copy($source, $data_file)) {
                $this->line('I\'ve added a sample scaffold.php in your app directory to get you started.');
            }
            exit();
        }

        include($data_file);

        if(!function_exists('data')) {
            $this->error('data function missing from scaffold.php');
            exit();
        }

        $this->data = data();

        $this->validateData();

        $this->collectUserData();

        // Generate migrations
        $this->makeMigrations();

        // Make sure the relationships we've detected are what the user wants.
        $this->checkRelationshipsWithUser();

        // Now we are aware of all tables add the relationships to the migrations
        $this->makeMigrationRelationships();

        $this->addInverseRelationships();

        // Generate models
        $this->makeModels();

        $this->writeFiles();

        return TRUE;
    }

    /**
     * Validate that the data returned from the data function has no problems.
     */
    private function validateData()
    {

        if(!is_array($this->data)) {
            $this->error('The data function must return an array');
            exit();
        }

        foreach($this->data as $tableName => $tableData) {

            // Check table has name
            if(empty($tableName)) {
                $this->error('Cannot have empty table name');
                exit();
            }

            // Check table has singular name
            if(empty($tableData['singular'])) {
                $this->error("Table $tableName array is missing the singular property");
                exit();
            }

            // Check columns array is present
            if(empty($tableData['columns'])) {
                $this->error("Table $tableName array is missing any columns");
                exit();
            }

            // Loop columns
            foreach($tableData['columns'] as $column) {

                // Every column must have a type
                if(empty($column['type'])) {
                    $this->error("A column in $tableName is missing the type property.");
                    exit();
                }

                // Check column type is valid.
                if(!method_exists($this->blueprint, $column['type'])) {
                    $this->error("A column in $tableName has the invalid type of " . $column['type']);
                    exit();
                }

                // use reflection to make sure the correct number of arguments have been provided.
                $method = new \ReflectionMethod($this->blueprint, $column['type']);
                $numParams = $method->getNumberOfParameters();
                $numRequiredParams = $method->getNumberOfRequiredParameters();

                if($numRequiredParams > 0 && empty($column['name'])) {
                    $this->error("A column in $tableName of type {$column['type']} is missing the required name property.");
                    exit();
                }

                // Make sure too many arguments have not been provided.
                $numArgsProvided = empty($column['name']) ? 0 : 1;
                $numArgsProvided += (empty($column['arguments']) ? 0 : count($column['arguments']));

                if($numArgsProvided > $numParams) {
                    if(empty($column['name'])) {
                        $msg = "A field in the $tableName table of type {$column['type']} has too many arguments.";
                    } else {
                        $msg = "The field {$column['name']} in the table $tableName has too many arguments.";
                    }

                    // Decrement the num params by one as column name is the first argument.
                    $numParams--;
                    $msg .= " {$column['type']} only accepts $numParams arguments.";
                    $this->error($msg);
                    exit();
                }

            }

            // Check indexes
            if(!empty($tableData['indexes'])) {
                foreach($tableData['indexes'] as $index) {
                    if(empty($index['type'])) {
                        $this->error("Table $tableName has an index without a type.");
                        exit();
                    }
                    if(empty($index['columns'])) {
                        $this->error("Table $tableName has an index without any columns.");
                        exit();
                    }
                    if(!is_string($index['columns']) && !is_array($index['columns'])) {
                        $this->error("Table $tableName has an index where the columns are not an array or a string.");
                        exit();
                    }
                }
            }
            
        }
    }

    /**
     * Collection options from user.
     */
    private function collectUserData()
    {
        $verbose = $this->option('verbose');

        if($verbose) {
          // Create migrations?
          $this->createMigrations = $this->confirm('Create migrations?', $this->createMigrations);

          // Create models and model namespace.
          $this->createModels = $this->confirm('Create models?', $this->createModels);

          if($this->createModels) {
            $this->modelNamespace = $this->ask('Model namespace', $this->modelNamespace);

            // Make sure the namespace has the correct slash in case the user got it wrong.
            $this->modelNamespace = str_replace('/', '\\', $this->modelNamespace);
          }

          // Create controllers and controller namespace.
          $this->createControllers = $this->confirm('Create controllers?', $this->createControllers);
          if($this->createControllers) {
            $this->controllerNamespace = $this->ask('Controller namespace', $this->controllerNamespace);

            // Make sure the namespace has the correct slash in case the user got it wrong.
            $this->controllerNamespace = str_replace('/', '\\', $this->controllerNamespace);
          }
        }

    }

    /**
     * Generate code for migrations.
     */
    private function makeMigrations()
    {
        foreach($this->data as $tableName => $tableData) {
            $this->migrations[$tableName] = $this->makeMigration($tableName, $tableData);
        }
    }

    /**
     * Generate code for a single migration.
     *
     * @param $tableName
     * @param $tableData
     * @return bool|mixed|string
     */
    private function makeMigration($tableName, $tableData)
    {
        $template = file_get_contents(__DIR__ . '/templates/migration.stub');

        $table = strtolower($tableName);
        $Table = ucfirst($table);

        $columns = $this->makeMigrationColumns($tableData['columns'], $tableName);
        $indexes = $this->makeMigrationIndexes($tableData);

        $search = ['{{table}}', '{{Table}}', '{{columns}}', '{{indexes}}'];
        $replace = [$table, $Table, $columns, $indexes];

        $template = str_replace($search, $replace, $template);

        return $template;

    }

    /**
     * Generate code for migration columns.
     *
     * @param $columns
     * @param $tableName
     * @return string
     */
    private function makeMigrationColumns($columns, $tableName)
    {
        $output = '';
        foreach($columns as $column) {
            $this->checkForRelationships($column, $tableName);
            $output .= $this->makeColumn($column, $tableName);
        }
        return $output;
    }

    /**
     * Generate code for single migration column.
     *
     * @param $column
     * @param $tableName
     * @return string
     */
    private function makeColumn($column, $tableName)
    {

        $columnName = isset($column['name']) ? $column['name'] : '';
        $columnType = $column['type'];

        // Keep a record of what table use soft deletes
        if($columnType == 'softDeletes' && !in_array($tableName, $this->softDeleteTables)) {
            $this->softDeleteTables[] = $tableName;
        }

        if(!empty($columnName)) {

            // Keep a record of fillable fields
            $this->fillable[$tableName][] = $columnName;

            // Keep a record of what tables use each field (use when determining relationships)
            if(!isset($this->fields[$columnName])) {
                $this->fields[$columnName] = [];
            }
            if(!in_array($tableName, $this->fields[$columnName])) {
                $this->fields[$columnName][] = $tableName;
            }
        }

        // Ok, now actually make the column.
        $output = '            ';
        $output .= '$table->' . $columnType . "('" . $columnName . "'";

        if(!empty($column['arguments'])) {

            // If there is only a single string argument wrap it in an array.
            if(is_string($column['arguments'])) {
                $column['arguments'] = [$column['arguments']];
            }

            foreach($column['arguments'] as $argument) {
                $argument = is_array($argument) ? $this->arrayToString($argument) : $argument;
                $output .= ', ' . $argument;
            }
        }

        $output .= ")";

        if(!empty($column['modifiers'])) {
            foreach($column['modifiers'] as $modifierName => $modifierArgument) {
                $output .= '->' . $modifierName . '(' . $modifierArgument . ')';
            }
        }

        $output .= ';' . PHP_EOL;

        return $output;
    }

    /**
     * Convert array to compact php >= 5.4 string format.
     *
     * @param $array
     * @return string
     */
    private function arrayToString($array)
    {
        $out = '[';
        foreach($array as $item) {
            $out .= "'" . $item . "', ";
        }
        $out = trim($out, ", ");
        $out .= ']';
        return $out;
    }

    /**
     * Check if the column passed in has a relationship with any other tables.
     *
     * @param $column
     * @param $thisColumnTableName
     */
    private function checkForRelationships($column, $thisColumnTableName)
    {

        if(!isset($column['name'])) {
            return;
        }

        if($column['type'] != 'integer' || strpos($column['name'], '_') == false) {
            return;
        }

        $parts = explode('_', $column['name']);
        $last = array_pop($parts);

        if($last != 'id') {
            return;
        }

        $tableName = implode('_', $parts) . 's';

        if(!array_key_exists($tableName, $this->data)) {
            return;
        }

        $isPivotTable = $this->isPivotTable($thisColumnTableName);

        $type = $isPivotTable ? 'belongsToMany' : 'belongsTo';

        $relationship = [
            'foreign'     => $column['name'],
            'references'  => 'id',
            'on'          => $tableName,
            'type'        => $type,
        ];

        $this->relationships[$thisColumnTableName][] = $relationship;
    }

    private function makeMigrationIndexes($tableData) {
        if(empty($tableData['indexes'])) {
            return '';
        }
        $indexes = '';
        foreach($tableData['indexes'] as $index) {
            $args = is_string($index['columns']) ? "'{$index['columns']}'" : $this->arrayToString($index['columns']);
            $indexes .= '            $table->' . $index['type'] . '(' . $args .');' . PHP_EOL;
        }
        return $indexes;
    }

    /**
     * Check if this table is a pivot table.
     * Check if based on if the table name consists of <TABLE-NAME>_<ANOTHER-TABLE-NAME>
     * Currently doesn't support table names with multiple underscores.
     *
     * @param $tableName
     * @return bool
     */
    private function isPivotTable($tableName)
    {

        if(strpos($tableName, '_') === false) {
            return false;
        }

        $parts = explode('_', $tableName);

        if(count($parts) > 2) {
            // TODO: Handle tables with more than one _
            trigger_error('Your table has multiple underscores. This makes me confused. I give up.', E_USER_ERROR);
            exit();
        } elseif(count($parts) < 2) {
            return false;
        }

        $part_0_is_table = false;
        $part_1_is_table = false;

        // If both parts are singular tables names then this is a pivot table
        foreach($this->data as $tableName => $tableData) {
            if($tableData['singular'] == $parts[0]) {
                $part_0_is_table = true;
            }
            if($tableData['singular'] == $parts[1]) {
                $part_1_is_table = true;
            }
        }

        if($part_0_is_table && $part_1_is_table) {
            return true;
        }

        return false;
    }

    /**
     * Make sure the auto detected relationships are what the user wants.
     */
    private function checkRelationshipsWithUser() {

        $this->line('Please confirm the following relationship types:');

        foreach($this->relationships as $tableName => &$relationships) {
            foreach($relationships as &$relationship) {

                // belongsTo could possible also be a hasOne but a belongsToMany could not be anything else.
                if($relationship['type'] == 'belongsTo') {

                    $msg = sprintf('%s belong to %s (one to many)', ucfirst($tableName), ucfirst($relationship['on']));
                    if(!$this->confirm($msg, true)) {

                        $singularSelf = $this->data[$tableName]['singular'];
                        $singularForeign = $this->data[$relationship['on']]['singular'];
                        $msg = sprintf('Ok, so does a %s have one %s? (one to one)', $singularSelf, $singularForeign);

                        if($this->confirm($msg)) {

                            // Update relationship type.
                            $relationship['type'] = 'hasOne';
                        } else {

                            $args = [$singularForeign, $singularSelf];
                            $msg = vsprintf('Got it, so a %s has one %s. (one to one the other way round)', $args);

                            // Just store this relationships inverse type for now.
                            $this->line($msg);
                            $relationship['inverseType'] = 'hasOne';
                        }
                    }
                }
            }
        }
    }

    /**
     * Generate for for the migration relationships.
     */
    private function makeMigrationRelationships()
    {
        foreach($this->migrations as $tableName => &$migration) {
            $relationships = '';
            if(array_key_exists($tableName, $this->relationships)) {
                foreach($this->relationships[$tableName] as $relationship) {
                    $relationships .= $this->makeMigrationRelationship($relationship);
                }

            }
            $migration = str_replace('{{relationships}}', $relationships, $migration);
        }
    }

    /**
     * Generate code for a single migration relationship.
     *
     * @param $relationship
     * @return string
     */
    private function makeMigrationRelationship($relationship)
    {
        $out = '            ';
        $out .= '$table->foreign(\'' . $relationship['foreign'] . "')->references('" . $relationship['references'] .
            "')->on('" . $relationship['references'] . "');" . PHP_EOL;

        return $out;
    }

    /**
     * Generate code for the models.
     */
    private function makeModels()
    {
        foreach($this->data as $tableName => $tableData) {
            $this->models[$tableName] = $this->makeModel($tableName, $tableData);
        }
    }

    /**
     * Generate code for a single model.
     *
     * @param $tableName
     * @param $tableData
     * @return bool|mixed|string
     */
    private function makeModel($tableName, $tableData)
    {
        // Pivot tables don't need models
        if($this->isPivotTable($tableName)) {
            return '';
        }

        $template = file_get_contents(__DIR__ . '/templates/model.stub');

        $Class = ucfirst(strtolower($tableData['singular']));
        $use = in_array($tableName, $this->softDeleteTables) ? '    use SoftDeletes;' : '';
        $table = strtolower($tableName);
        $fillable = "'" . implode("', '", $this->fillable[$tableName]) . "'";
        $relationships = $this->makeModelRelationships($tableName);

        $search = ['{{Class}}', '{{use}}', '{{table}}', '{{fillable}}', '{{relationships}}'];
        $replace = [$Class, $use, $table, $fillable, $relationships];

        $template = str_replace($search, $replace, $template);

        return $template;

    }

    /**
     * Generate code for the model relationships.
     *
     * @param $tableName
     * @return string
     */
    private function makeModelRelationships($tableName)
    {
        $out = '';

        if(!empty($this->relationships[$tableName])) {

            $singularSelf = strtolower($this->data[$tableName]['singular']);

            foreach($this->relationships[$tableName] as $relationship) {

                $pluralForeign = $relationship['on'];
                $singularForeign = strtolower($this->data[$pluralForeign]['singular']);
                $SingularForeign = ucfirst($singularForeign);

                $path = __DIR__ . '/templates/modelRelationship/' . $relationship['type'] . '.stub';
                $template = file_get_contents($path);

                $search = [
                    '{{singularForeign}}',
                    '{{SingularForeign}}',
                    '{{singularSelf}}',
                    '{{pluralForeign}}'
                ];
                $replace = [
                    $singularForeign,
                    $SingularForeign,
                    $singularSelf,
                    $pluralForeign
                ];

                $out .= str_replace($search, $replace, $template) . PHP_EOL . PHP_EOL;

            }
        }

        return $out;

    }

    /**
     * Based on the existing relationships add the inverse for each.
     */
    private function addInverseRelationships()
    {
        if(!empty($this->relationships)) {

            foreach($this->relationships as $tableName => $relationships) {
                foreach($relationships as $relationship) {
                    if($relationship['type'] == 'belongsTo') {

                        // Check if there is already an inverse type defined for this relationship.
                        $type = !empty($relationship['inverseType']) ? $relationship['inverseType'] : 'hasMany';

                        // Make the hasMany
                        $this->relationships[$relationship['on']][] = array(
                            'references' => 'id',
                            'on' => $tableName,
                            'type' => $type,
                        );
                    } elseif($relationship['type'] == 'belongsToMany') {

                        // We need to get the plural inverse of the table
                        $parts = explode('_', $tableName);
                        $singularTable1 = $parts[0];
                        $singularTable2 = $parts[1];

                        $pluralTable1 = $this->getPluralTableName($singularTable1);
                        $pluralTable2 = $this->getPluralTableName($singularTable2);

                        $on = $relationship['on'] == $pluralTable1 ? $pluralTable2 : $pluralTable1;

                        $this->relationships[$relationship['on']][] = array(
                            'references' => 'id',
                            'on' => $on,
                            'type' => 'belongsToMany',
                        );
                    }
                }
            }
        }
    }

    /**
     * Given a singular table name return the plural.
     *
     * @param $singularTableName
     * @return int|string
     */
    private function getPluralTableName($singularTableName)
    {
        foreach($this->data as $tableName => $tableData) {
            if($tableData['singular'] == $singularTableName) {
                return $tableName;
            }
        }

        return FALSE;
    }

    /**
     * Write files to disk.
     */
    private function writeFiles()
    {

        if($this->createMigrations) {
            $migrationPath = base_path('database/migrations');

            foreach($this->migrations as $tableName => $migration) {
                $migrationFileName = date('Y_m_d_') . time() . '_create_' . $tableName . '_table.php';
                $filePath = $migrationPath . DIRECTORY_SEPARATOR . $migrationFileName;
                $this->writeFile($filePath, $migration, 'migration');
            }
        }

        if($this->createModels) {

            // Get the correct model path based on the namespace provided.
            $path = str_replace('App', '', $this->modelNamespace);
            $path = $this->nameSpaceToPath($path);
            $modelPath = app_path($path);

            $this->modelNamespace;

            foreach($this->models as $tableName => $model) {
                $singularName = $this->data[$tableName]['singular'];
                $modelFileName = ucfirst(strtolower($singularName)) . '.php';
                $filePath = $modelPath . DIRECTORY_SEPARATOR . $modelFileName;
                $this->writeFile($filePath, $model, 'model');
            }
        }

        if($this->createControllers) {
            $this->createControllers();
        }

    }

    /**
     * Write a single file.
     *
     * @param $filePath
     * @param $contents
     * @param $type
     */
    private function writeFile($filePath, $contents, $type)
    {
        if(empty($contents)) {
            return;
        }

        // If file exists ask user if they want to overwrite it.
        if(file_exists($filePath)) {
            if($this->confirm(ucfirst($type) . ' ' . $filePath . ' already exists. Overwrite?')) {
                unlink($filePath);
            }
        }

        if(!file_exists($filePath)) {
            file_put_contents($filePath, $contents);
            $this->info('Wrote file ' . $filePath);
        }
    }

    /**
     * Call artisan command to generate controllers.
     */
    private function createControllers()
    {
        foreach($this->data as $tableName => $tableData) {

            // Pivot tables don't need controllers
            if($this->isPivotTable($tableName)) {
                return;
            }

            $SingularSelf = ucfirst(strtolower($tableData['singular']));
            $controllerName = $SingularSelf . 'Controller';

            $actualPath = str_replace('App/', 'app/', $this->nameSpaceToPath($this->controllerNamespace))
                . DIRECTORY_SEPARATOR . $controllerName . '.php';

            $createController = TRUE;
            if(file_exists($actualPath)) {
                $createController = FALSE;
                if($this->confirm('Controller ' . $controllerName . ' already exists. Overwrite?')) {
                    if(unlink($actualPath)) {
                        $createController = TRUE;
                    } else {
                        $this->error('Could not overwrite ' . $controllerName);
                    }
                }
            }

            if($createController) {

                // Build correct path to pass into make:controller
                $controllerPath = str_replace('App\Http\Controllers', '', $this->controllerNamespace);
                $controllerPath = $this->nameSpaceToPath($controllerPath);
                $controllerPath = empty($controllerPath) ? '' : $controllerPath . DIRECTORY_SEPARATOR;

                $this->call('make:controller', [
                    'name' => $controllerPath . $controllerName,
                    '--resource' => true,
                    '--model' => $this->modelNamespace . '\\' . $SingularSelf,
                ]);
            }
        }
    }

    /**
     * Convert namespace to path by replacing all slashes with the correct directory separators.
     *
     * @param $namespace
     * @return string
     */
    private function nameSpaceToPath($namespace) {
        $search = ['/', '\\'];
        $replace = [DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR];
        $path = str_replace($search, $replace, $namespace);
        return trim($path, DIRECTORY_SEPARATOR . ' ');
    }

}
