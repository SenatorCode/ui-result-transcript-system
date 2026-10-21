<?php



class Result {
     private PDO $db;
     public function __construct (PDO $db) {
        $this->db = $db;
     } 
     public function getOptions(int $studentId): array{
      $stmt = $this ->db ->prepare(
         "SELECT DISTINCT session  , semester, sort_order
         FROM v_result_details 
         WHERE student_id=  ? 
         ORDER BY SESSION DESC , sort_order DESC "
      );
      $stmt -> execute([$studentId]);

      $pairs=[] ;
      foreach ($stmt-> fetchALL(PDO::FETCH_ASSOC)as $row){
      $pairs[] = [
              'session' => $row ['session'],
              'semester' => $row ['semester'],
              'released' => true ,
      ];
     }
     return $pairs;
  } 
        public function getSemesterResults ( int $studentId  , string  $session, string $semester): ?array { 
      $stmt = $this ->db ->prepare(
         "SELECT  session  , semester, code ,title , unit ,grade ,grade_point
         FROM v_result_details 
         WHERE student_id = ? 
         ORDER BY session , sort_order ,code   "
      );
       $stmt-> execute([$studentId]);
       $rows= $stmt ->  fetchAll(PDO::FETCH_ASSOC);

        $attempts = [];   
        $latest = [];     
        $courses = [];
        $found = false;
        $semPoints = 0.0;
        $semUnits = 0;
        $semEarned = 0;
          
         foreach ($rows as $row ) {
            $ischosen=($row['session'] === $session && $row ['semester']== $semester);
            if ($found && !$ischosen){
               break;
            }
         $code =$row ['code'];
         $unit = (int)$row ['unit'];
         $points =(float)$row ['grade_point'];
          $attempts[$code] = ($attempts[$code] ?? 0) + 1;
            $latest[$code]   = ['unit' => $unit, 'points' => $points];
 if ($ischosen) { 
   $found =true;
$earned =($row ['grade']=='F') ? 0 : $unit ;
   $semUnits += $unit ;
    $semEarned += $earned;
$semPoints +=$unit  *  $points;
   
 $courses[] = [
       'code' =>$code, 
       'title' => $row['title'],
  'units' => $unit,
'unitsEarned' =>  $earned, 
'grade' => $row ['grade'],
'gradePoint' => $points,
'attempt' =>  $attempts[$code],
'carryOver' => $attempts[$code] > 1,
 ];
}
   } 
    if (!$found) {
       return null ;
    }

   $cgpaUnits = 0 ; 
   $cgpaPonits =0.0 ; 
    foreach ($latest as $course) { 
     $cgpaUnits +=$course['unit'];
      $cgpaPonits +=$course  ['unit'] * $course ['points'];
    } 
       $cgpa = round ($cgpaPonits / $cgpaUnits ,2); 
         
        return [ 
          'session' => $session,
         'semester' => $semester,
         'availability' => 'released' , 
         'courses' => $courses , 
         'summary' => [ 
             'gpa' => round($semPoints / $semUnits,2 ),
             'cgpa' => $cgpa, 
             'unitsRegistered' => $semUnits,
             'unitsEarned' => $semEarned, 
             'degreeClass' => $this -> degreeClass ( $cgpa),
         ],
             
      ];
   }
        private function degreeClass (float $cgpa ) : string {
         if ($cgpa >= 4.50) return 'First Class Honours'; 
         if ($cgpa >= 3.50) return 'Second Class Honours (Upper Division)'; 
        if  ($cgpa >= 2.40) return 'Second Class Honours (Lower Division)'; 
        if  ($cgpa >= 1.50) return 'Third Class Honours'; 
        if  ($cgpa >= 1.00) return 'Pass'; 
         return 'Fail' ; 
        }
      } 

          
        
      



    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
