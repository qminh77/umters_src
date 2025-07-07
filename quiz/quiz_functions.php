<?php
/**
* Helper functions for handling quizlet functionality
*/

/**
* Process content and convert media tags to HTML
* 
* @param string $content The content to process
* @return string The processed content with media tags converted to HTML
*/
function process_quiz_media_content($content) {
  // Process image tags: [img:URL]
  $content = preg_replace_callback('/\[img:(.*?)\]/', function($matches) {
      $url = trim($matches[1]);
      // Validate URL
      if (filter_var($url, FILTER_VALIDATE_URL) || file_exists($url)) {
          return '<div class="quiz-media quiz-image">
              <img src="' . htmlspecialchars($url) . '" alt="Quiz Image" class="img-fluid" loading="lazy" onload="this.parentNode.classList.add(\'loaded\')">
              <div class="media-loading"><i class="fas fa-spinner fa-spin"></i></div>
          </div>';
      }
      return $matches[0]; // Return original if invalid
  }, $content);
  
  // Process video tags: [video:URL]
  $content = preg_replace_callback('/\[video:(.*?)\]/', function($matches) {
      $url = trim($matches[1]);
      // Validate URL
      if (filter_var($url, FILTER_VALIDATE_URL) || file_exists($url)) {
          return '<div class="quiz-media quiz-video">
              <video controls class="video-player" preload="metadata" onloadstart="this.parentNode.classList.add(\'loading\')" oncanplay="this.parentNode.classList.add(\'loaded\')">
                  <source src="' . htmlspecialchars($url) . '" type="video/mp4">
                  Your browser does not support the video tag.
              </video>
              <div class="media-loading"><i class="fas fa-spinner fa-spin"></i></div>
          </div>';
      }
      return $matches[0]; // Return original if invalid
  }, $content);
  
  // Process YouTube tags: [youtube:VIDEO_ID]
  $content = preg_replace_callback('/\[youtube:(.*?)\]/', function($matches) {
      $video_id = trim($matches[1]);
      
      // Extract video ID if full URL was provided
      if (preg_match('/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $video_id, $id_matches)) {
          $video_id = $id_matches[3];
      }
      
      // Validate YouTube video ID (should be 11 characters)
      if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $video_id)) {
          return '<div class="quiz-media quiz-youtube">
              <div class="media-loading"><i class="fas fa-spinner fa-spin"></i></div>
              <iframe width="100%" height="200" src="https://www.youtube.com/embed/' . $video_id . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen onload="this.parentNode.classList.add(\'loaded\')"></iframe>
          </div>';
      }
      return $matches[0]; // Return original if invalid
  }, $content);
  
  return $content;
}

/**
* Generate a unique share token for a quiz set
* 
* @param int $quiz_id The quiz ID
* @return string The generated share token
*/
function generate_quiz_share_token($quiz_id) {
   // Generate a random token
   $token = bin2hex(random_bytes(16));
   return $token;
}

/**
* Upload an image file for quiz questions and return the URL
* 
* @param array $file The uploaded file ($_FILES array element)
* @param string $upload_dir The directory to upload to
* @return string|false The URL of the uploaded file or false on failure
*/
function upload_quiz_media_file($file, $upload_dir = '../uploads/quiz_media/') {
   // Create directory if it doesn't exist
   if (!file_exists($upload_dir)) {
       mkdir($upload_dir, 0755, true);
   }
   
   // Generate unique filename
   $filename = uniqid() . '_' . basename($file['name']);
   $target_file = $upload_dir . $filename;
   
   // Check file type
   $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm'];
   if (!in_array($file['type'], $allowed_types)) {
       return false;
   }
   
   // Upload file
   if (move_uploaded_file($file['tmp_name'], $target_file)) {
       return $target_file;
   }
   
   return false;
}

/**
* Import quiz questions from CSV file
* 
* @param string $file_path Path to the CSV file
* @param int $quiz_id The quiz set ID
* @param int $user_id The user ID
* @param bool $has_header Whether the CSV file has a header row
* @param object $conn Database connection
* @return array Array with count of imported questions and any errors
*/
function import_quiz_questions_from_csv($file_path, $quiz_id, $user_id, $has_header = false, $conn) {
   $result = [
       'count' => 0,
       'errors' => []
   ];
   
   if (!file_exists($file_path)) {
       $result['errors'][] = 'File không tồn tại';
       return $result;
   }
   
   $handle = fopen($file_path, "r");
   if ($handle === FALSE) {
       $result['errors'][] = 'Không thể đọc file';
       return $result;
   }
   
   // Skip header row if needed
   if ($has_header) {
       fgetcsv($handle, 1000, ",");
   }
   
   while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
       // Check if we have at least 6 columns: question, option1, option2, option3, option4, correct_answer
       if (count($data) >= 6) {
           $question = mysqli_real_escape_string($conn, $data[0]);
           $option1 = mysqli_real_escape_string($conn, $data[1]);
           $option2 = mysqli_real_escape_string($conn, $data[2]);
           $option3 = mysqli_real_escape_string($conn, $data[3]);
           $option4 = mysqli_real_escape_string($conn, $data[4]);
           $correct_answer = (int)$data[5];
           
           // Validate correct answer (should be 1-4)
           if ($correct_answer < 1 || $correct_answer > 4) {
               $correct_answer = 1; // Default to first option if invalid
           }
           
           // Optional explanation if provided
           $explanation = '';
           if (isset($data[6])) {
               $explanation = mysqli_real_escape_string($conn, $data[6]);
           }
           
           $sql = "INSERT INTO quiz_questions (quiz_id, question, option1, option2, option3, option4, correct_answer, explanation, created_at) 
                   VALUES ($quiz_id, '$question', '$option1', '$option2', '$option3', '$option4', $correct_answer, '$explanation', NOW())";
           
           if (mysqli_query($conn, $sql)) {
               $result['count']++;
           } else {
               $result['errors'][] = 'Lỗi khi thêm câu hỏi: ' . mysqli_error($conn);
           }
       } else {
           $result['errors'][] = 'Dòng không đủ dữ liệu: cần ít nhất 6 cột';
       }
   }
   
   fclose($handle);
   return $result;
}

/**
* Share a quiz set with another user
* 
* @param int $quiz_id The quiz set ID
* @param string $share_token The share token
* @param int $target_user_id The user ID to share with
* @param object $conn Database connection
* @return bool|array Success status or array with error message
*/
function share_quiz_set($quiz_id, $share_token, $target_user_id, $conn) {
   // First, verify the share token is valid
   $sql_verify = "SELECT * FROM quiz_sets WHERE id = $quiz_id AND share_token = '$share_token'";
   $result_verify = mysqli_query($conn, $sql_verify);
   
   if (mysqli_num_rows($result_verify) == 0) {
       return ['success' => false, 'message' => 'Token chia sẻ không hợp lệ'];
   }
   
   $quiz_set = mysqli_fetch_assoc($result_verify);
   $source_user_id = $quiz_set['user_id'];
   
   // Don't allow sharing with self
   if ($source_user_id == $target_user_id) {
       return ['success' => false, 'message' => 'Không thể chia sẻ với chính mình'];
   }
   
   // Create a copy of the quiz set for the target user
   $quiz_name = mysqli_real_escape_string($conn, $quiz_set['name']);
   $quiz_description = mysqli_real_escape_string($conn, $quiz_set['description']);
   
   $sql_copy_set = "INSERT INTO quiz_sets (user_id, name, description, created_at) 
                    VALUES ($target_user_id, '$quiz_name (Shared)', '$quiz_description', NOW())";
   
   if (!mysqli_query($conn, $sql_copy_set)) {
       return ['success' => false, 'message' => 'Lỗi khi tạo bản sao: ' . mysqli_error($conn)];
   }
   
   $new_quiz_id = mysqli_insert_id($conn);
   
   // Copy all questions
   $sql_questions = "SELECT * FROM quiz_questions WHERE quiz_id = $quiz_id";
   $result_questions = mysqli_query($conn, $sql_questions);
   
   while ($question = mysqli_fetch_assoc($result_questions)) {
       $q_text = mysqli_real_escape_string($conn, $question['question']);
       $opt1 = mysqli_real_escape_string($conn, $question['option1']);
       $opt2 = mysqli_real_escape_string($conn, $question['option2']);
       $opt3 = mysqli_real_escape_string($conn, $question['option3']);
       $opt4 = mysqli_real_escape_string($conn, $question['option4']);
       $correct = (int)$question['correct_answer'];
       $explanation = mysqli_real_escape_string($conn, $question['explanation']);
       
       $sql_copy_question = "INSERT INTO quiz_questions (quiz_id, question, option1, option2, option3, option4, correct_answer, explanation, created_at) 
                            VALUES ($new_quiz_id, '$q_text', '$opt1', '$opt2', '$opt3', '$opt4', $correct, '$explanation', NOW())";
       
       mysqli_query($conn, $sql_copy_question);
   }
   
   return ['success' => true, 'quiz_id' => $new_quiz_id];
}

/**
* Shuffle quiz options while keeping track of the correct answer
* 
* @param array $question The question array with options and correct answer
* @return array The question with shuffled options and updated correct answer
*/
function shuffle_quiz_options($question) {
  // Create an array of options with their original indices
  $options = [
      1 => $question['option1'],
      2 => $question['option2'],
      3 => $question['option3'],
      4 => $question['option4']
  ];
  
  // Remember which option was correct
  $correct_answer = $question['correct_answer'];
  $correct_option_value = $options[$correct_answer];
  
  // Create an array of option indices and shuffle it
  $indices = [1, 2, 3, 4];
  shuffle($indices);
  
  // Create new shuffled options array
  $shuffled_options = [];
  $new_correct_index = 0;
  
  // Rebuild the options array with shuffled indices
  foreach ($indices as $new_index => $old_index) {
      $new_position = $new_index + 1; // Convert to 1-based indexing
      $shuffled_options[$new_position] = $options[$old_index];
      
      // If this is the correct option, update the correct answer index
      if ($old_index == $correct_answer) {
          $new_correct_index = $new_position;
      }
  }
  
  // Update the question with shuffled options
  $question['option1'] = $shuffled_options[1];
  $question['option2'] = $shuffled_options[2];
  $question['option3'] = $shuffled_options[3];
  $question['option4'] = $shuffled_options[4];
  $question['correct_answer'] = $new_correct_index;
  
  return $question;
}

/**
* Calculate quiz statistics for a user
* 
* @param int $quiz_id The quiz set ID
* @param int $user_id The user ID
* @param object $conn Database connection
* @return array Quiz statistics
*/
function get_quiz_statistics($quiz_id, $user_id, $conn) {
   $stats = [
       'total_questions' => 0,
       'attempted' => 0,
       'correct' => 0,
       'incorrect' => 0,
       'accuracy' => 0,
       'completion' => 0
   ];
   
   // Get total number of questions
   $sql_total = "SELECT COUNT(*) as total FROM quiz_questions WHERE quiz_id = $quiz_id";
   $result_total = mysqli_query($conn, $sql_total);
   $row_total = mysqli_fetch_assoc($result_total);
   $stats['total_questions'] = $row_total['total'];
   
   // Get progress data
   $sql_progress = "SELECT * FROM quiz_progress WHERE user_id = $user_id AND quiz_id = $quiz_id";
   $result_progress = mysqli_query($conn, $sql_progress);
   
   while ($progress = mysqli_fetch_assoc($result_progress)) {
       $stats['attempted']++;
       
       if ($progress['is_correct']) {
           $stats['correct']++;
       } else {
           $stats['incorrect']++;
       }
   }
   
   // Calculate accuracy and completion
   if ($stats['attempted'] > 0) {
       $stats['accuracy'] = round(($stats['correct'] / $stats['attempted']) * 100);
   }
   
   if ($stats['total_questions'] > 0) {
       $stats['completion'] = round(($stats['attempted'] / $stats['total_questions']) * 100);
   }
   
   return $stats;
}

/**
* Format a CSV template for quiz questions
* 
* @return string CSV template content
*/
function get_quiz_csv_template() {
   $header = "Question,Option 1,Option 2,Option 3,Option 4,Correct Answer (1-4),Explanation (Optional)\n";
   $example1 = "What is the capital of France?,Paris,London,Berlin,Madrid,1,Paris is the capital of France\n";
   $example2 = "Which planet is known as the Red Planet?,Earth,Mars,Jupiter,Venus,2,Mars is known as the Red Planet due to its reddish appearance\n";
   
   return $header . $example1 . $example2;
}

/**
* Export quiz questions to CSV
* 
* @param int $quiz_id The quiz set ID
* @param object $conn Database connection
* @return string CSV content
*/
function export_quiz_to_csv($quiz_id, $conn) {
   $csv = "Question,Option 1,Option 2,Option 3,Option 4,Correct Answer (1-4),Explanation\n";
   
   $sql = "SELECT * FROM quiz_questions WHERE quiz_id = $quiz_id ORDER BY id ASC";
   $result = mysqli_query($conn, $sql);
   
   while ($row = mysqli_fetch_assoc($result)) {
       $csv .= '"' . str_replace('"', '""', $row['question']) . '",';
       $csv .= '"' . str_replace('"', '""', $row['option1']) . '",';
       $csv .= '"' . str_replace('"', '""', $row['option2']) . '",';
       $csv .= '"' . str_replace('"', '""', $row['option3']) . '",';
       $csv .= '"' . str_replace('"', '""', $row['option4']) . '",';
       $csv .= $row['correct_answer'] . ',';
       $csv .= '"' . str_replace('"', '""', $row['explanation']) . '"';
       $csv .= "\n";
   }
   
   return $csv;
}
