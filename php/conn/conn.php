<?php
class Database {
    private $host = "sql304.infinityfree.com";
    private $db_name = "if0_41634817_nyc_crashdata";
    private $username = "if0_41634817";
    private $password = "F0unlxt1p1A6UoF";

    public $conn;

    public function getConn() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $ex) {
            echo "Database could not be connected: " . $ex->getMessage();
        }

        return $this->conn;
    }
}
?>