<?php declare(strict_types=1); ?>
<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'nagex_pharma_db';
    private $username = 'root';
    private $password = '';
    public $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username, 
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            // Log l'erreur complète
            error_log("Erreur DB Connexion: " . $exception->getMessage());
            throw new Exception("Erreur de connexion à la base de données: " . $exception->getMessage());
        }
        
        return $this->conn;
    }
    
    public function prepare($sql) {
        try {
            return $this->getConnection()->prepare($sql);
        } catch (Exception $e) {
            error_log("Erreur DB Prepare: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function exec($sql) {
        try {
            return $this->getConnection()->exec($sql);
        } catch (Exception $e) {
            error_log("Erreur DB Exec: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function lastInsertId() {
        try {
            return $this->getConnection()->lastInsertId();
        } catch (Exception $e) {
            error_log("Erreur DB LastInsertId: " . $e->getMessage());
            throw $e;
        }
    }
}
?>