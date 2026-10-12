<?php


 class Course{ 
     private PDO $db;
     public function __construct (PDO $db) {
        $this->db = $db;
     }

     public function getByID  (int $id ): ?array { 
         $stmt = $this ->db -> prepare  ( 
            "SELECT c.id ,c.title , c.code , c.unit  , d.name AS department
            FROM courses c 
            JOIN departments d  ON d.id = c.department_id 
        where c.id = ? "
         );
          $stmt -> execute([$id]);
          $row = $stmt-> fetch(PDO::FETCH_ASSOC);
          return $row ? $this ->format($row): null;
        
        }
        public function getByCode(string $Code): ? array { 
         $stmt = $this ->db -> prepare  ( 
            "SELECT c.id ,c.title , c.code , c.unit  , d.name AS department
            FROM courses c 
            JOIN departments d  ON d.id = c.department_id 
        where c.code= ? "
         );
          $stmt -> execute([$Code]);
          $row = $stmt-> fetch(PDO::FETCH_ASSOC);
          return $row ? $this -> format($row) : null;
        }

         public function getALL(): array{
            $stmt= $this ->db->query ( 
                "SELECT c.id ,c.title , c.code , c.unit  , d.name AS department
            FROM courses c 
            JOIN departments d  ON d.id = c.department_id 
            ORDER BY c.code");
            return array_map ([$this, 'format'], $stmt -> fetchALL(PDO::FETCH_ASSOC));
        }

     private function format (array $row) : array {
        return [ 'id'    => (int)$row['id'],
            'code'       => $row['code'],
            'title'      => $row['title'],
            'unit'       => (int)$row['unit'],
            'department' => $row['department'],
        ];
            
     }
    }

    
