<?php
namespace LabelCenter\Db;

class BaseRecordHandler
{
    private PgConnection $pg;
    private string $gridName;
    private string $tableName;
    private array $mapping;

    public function __construct(PgConnection $pg, string $gridName, string $tableName, array $mapping)
    {
        $this->pg = $pg;
        $this->gridName = $gridName;
        $this->tableName = $tableName;
        $this->mapping = $mapping;
    }

    public function handleRequest(): void
    {
        try {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            
            if (!$input) throw new \Exception("Neplatný JSON na vstupu.");
            
            // Validace gridu
            if (($input['grid'] ?? '') !== $this->gridName) {
                throw new \Exception("Neshoda gridu. Očekáváno: {$this->gridName}");
            }

            $operation = $input['operation'] ?? '';

            switch ($operation) {
                case 'insert':
                    $this->processInsert($input['data'] ?? []);
                    break;
                case 'update':
                    $this->processUpdate($input['data'] ?? []);
                    break;
                case 'delete':
                    $this->processDelete($input['key'] ?? []);
                    break;
                default:
                    throw new \Exception("Neznámá operace: $operation");
            }
        } catch (\Throwable $e) {
            $this->sendResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function processInsert(array $datasets): void
    {
        if (empty($datasets)) throw new \Exception("Chybí data pro insert.");
        
        $this->pg->beginTransaction();
        $insertedIds = [];

        foreach ($datasets as $data) {
            $cols = [];
            $vals = [];
            $params = [];

            foreach ($this->mapping as $dbCol => $jsonKey) {
                if ($dbCol === 'id') continue; // ID generuje Postgres IDENTITY
                
                $cols[] = "\"$dbCol\"";
                $vals[] = ":$jsonKey";
                
                // Ošetření JSONB pro meta_json
                $val = $data[$jsonKey] ?? null;
                $params[":$jsonKey"] = is_array($val) ? json_encode($val) : $val;
            }

            $sql = "INSERT INTO {$this->tableName} (" . implode(', ', $cols) . ") 
                    VALUES (" . implode(', ', $vals) . ") RETURNING id";
            
            // query v PgConnection vrací fetchAll, vezmeme první řádek
            $res = $this->pg->query($sql, $params);
            $insertedIds[] = (string)$res[0]['id'];
        }

        $this->pg->commit();
        $this->sendResponse(['ids' => $insertedIds]);
    }

    private function processUpdate(array $datasets): void
    {
        if (empty($datasets)) throw new \Exception("Chybí data pro update.");

        $this->pg->beginTransaction();
        foreach ($datasets as $data) {
            if (!isset($data['id'])) throw new \Exception("Chybí ID u záznamu pro update.");

            $sets = [];
            $params = [':id' => $data['id']];

            foreach ($this->mapping as $dbCol => $jsonKey) {
                if ($dbCol === 'id') continue;
                
                // ZMĚNA: Přidáme do SETu jen to, co v JSONu skutečně existuje
                if (array_key_exists($jsonKey, $data)) {                
                        $sets[] = "\"$dbCol\" = :$jsonKey";
                        $val = $data[$jsonKey] ?? null;
                        $params[":$jsonKey"] = is_array($val) ? json_encode($val) : $val;

                    }

            }

            $sql = "UPDATE {$this->tableName} SET " . implode(', ', $sets) . " WHERE id = :id";
            $this->pg->exec($sql, $params);
        }

        $this->pg->commit();
        $this->sendResponse(['success' => true]);
    }

    private function processDelete(array $keys): void
    {
        if (empty($keys)) throw new \Exception("No keys to delete");

        $this->pg->beginTransaction();
        foreach ($keys as $key) {
            if (!isset($key['id'])) throw new \Exception("Chybí ID pro delete.");
            $this->pg->exec("DELETE FROM {$this->tableName} WHERE id = :id", [':id' => $key['id']]);
        }

        $this->pg->commit();
        $this->sendResponse(['success' => true]);
    }

    private function sendResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
