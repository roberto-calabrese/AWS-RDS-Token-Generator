    <?php

    require 'vendor/autoload.php';

    use Fostam\GetOpts\Handler;
    use function Laravel\Prompts\multiselect;
    use function Laravel\Prompts\info;
    use function Laravel\Prompts\error;
    use function Laravel\Prompts\table;
    use function Laravel\Prompts\confirm;

    function loadConfig($configFile) {
        try {
            $config = file_get_contents($configFile, false);
            return collect(json_decode($config, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            die("Errore nel file di configurazione: {$e->getMessage()}");
        }
    }

    function getConnectionChoices($config): array
    {
        $connection_choices = [];
        foreach ($config->get('connections') as $connection) {
            $connection_choices[$connection['name']] = $connection['name']." -> ".$connection['hostname'];
        }
        return $connection_choices;
    }

    function selectConnections($connection_choices): array
    {
        return multiselect(
            label: 'Seleziona le connessioni:',
            options: $connection_choices,
            validate: fn (array $values) => !$values ? 'Devi selezionare almeno una connessione' : null,
            hint: "Usa la barra spaziatrice per selezionare"
        );
    }

    function mapSelectedConnections($selected_connections, $config): array
    {
        return array_map(static function ($selected_connection) use ($config) {
            $connection_config = null;
            foreach ($config['connections'] as $connection) {
                if ($connection['name'] === $selected_connection) {
                    $connection_config = $connection;
                    break;
                }
            }
            $connection_config = array_merge($config['fallback'], $connection_config ?? []);

            // Ordino array
            return  [
                'name' => $connection_config['name'],
                'hostname' => $connection_config['hostname'],
                'port' => $connection_config['port'],
                'username' => $connection_config['username'],
                'aws_profile' => $connection_config['aws_profile'],
                'aws_region' => $connection_config['aws_region']
            ];
        }, $selected_connections);
    }

    function displaySelectedConnections($selected_connections): void
    {
        table(
            ['Name', 'Hostname', 'Port', 'Username', 'Aws Profile', 'Aws Region'],
            $selected_connections
        );
    }

    function confirmProceed(): bool
    {
        return confirm(
            label: 'Vuoi richiedere i token per queste connessioni?',
            default: true,
            yes: 'Si',
            no: 'No, torna indietro',
            hint: 'Richieste dei token tramite: "rds generate-db-auth-token"'
        );
    }

    function executeAWSCommands($selected_connections): void
    {
        foreach ($selected_connections as $connection) {
            // Controlla se i campi necessari sono presenti nell'array
            if (!isset($connection['hostname'], $connection['aws_profile'], $connection['port'], $connection['aws_region'], $connection['username'], $connection['name'])) {
                error("Errore: uno o più campi necessari mancano nella configurazione della connessione.");
                continue;
            }

            // Ottengo un hostname valido
            $hostname = checkHostname($connection['hostname']);
            if (!$hostname) {
                error("Errore durante la valutazione dell'hostname: {$connection['hostname']}");
                continue;
            }

            $command = "aws --profile {$connection['aws_profile']} rds generate-db-auth-token \
                --hostname {$hostname} \
                --port {$connection['port']} \
                --region {$connection['aws_region']} \
                --username {$connection['username']}";
            $output = shell_exec($command);
            info("Connection: {$connection['name']}\nToken: $output");
        }
    }

    /**
     * Controlla e corregge l'hostname se necessario.
     *
     * Questa funzione prende un hostname come input e controlla se termina con "rds.amazonaws.com".
     * Se l'hostname non termina con "rds.amazonaws.com", esegue una verifica DNS per ottenere il vero hostname.
     *
     * @param string $hostname L'hostname da controllare.
     * @return string|null L'hostname corretto se esiste, altrimenti null.
     */
    function checkHostname(string $hostname): ?string {
        if (!str_ends_with($hostname, "rds.amazonaws.com")) {
            $dns = dns_get_record($hostname, DNS_CNAME);
            return $dns[0]['target'] ?? null;
        }
        return $hostname;
    }


    // Crea un nuovo gestore di opzioni
    $options = new Handler();

    // Aggiungi l'opzione per il file di configurazione
    $options->addOption('file_config')
        ->short('c')
        ->long('config')
        ->description('file di configurazione JSON')
        ->argument('input-file')
        ->defaultValue('config.json');

    try {
        $options->parse();
        $config = loadConfig($options->get()['file_config']);
    } catch (\Fostam\GetOpts\Exception\UsageException $e) {
        die($e->getMessage());
    }

    $connection_choices = getConnectionChoices($config);
    $selected_connections = selectConnections($connection_choices);
    $selected_connections = mapSelectedConnections($selected_connections, $config);
    displaySelectedConnections($selected_connections);

    while (true) {
        if (confirmProceed()) {
            executeAWSCommands($selected_connections);
            break;
        }

        $selected_connections = selectConnections($connection_choices);
        $selected_connections = mapSelectedConnections($selected_connections, $config);
        displaySelectedConnections($selected_connections);
    }