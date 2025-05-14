<?php
session_start();
require_once 'db/db.php';
require_once 'vendor/autoload.php'; // for PDFParser

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    header("Location: login");
    exit;
}

function analyzeWithGPT($rawText) {
    $apiKey = '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';
    $endpoint = 'https://api.openai.com/v1/chat/completions';

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    $data = [
        'model' => 'gpt-4-turbo',
        'messages' => [
            [
                'role' => 'system',
               'content' => 'You are an AI resume parser. Extract the following fields from the provided resume text and return the result strictly as clean JSON (no markdown or code blocks). Follow the instructions below:

                - phone: contact number as a string
                - home_address: location as written in the resume
                - skills: list of individual skills or technologies (as an array of strings)
         
                - experience: list of summarized roles or areas of expertise, including self-learning or coursework-based experience if applicable. For each, include:
                                - title: the main focus area (e.g., Web Development, Graphic Design)
                                - organization or source: name of school, platform, or note if its self-taught
                                - date_range: if not specified, infer based on phrases like "past few months"
                                - responsibilities: bullet-point list summarizing what was done or learned

                - program: the main academic program title (e.g., "Bachelor of Science in Computer Science")

                - education_status: get the example (e.g 09/2020 - 08/2024, January 2020 - August 2024, etc) graduation year or if the resume has no graduation year, copy the text beside it. e.g "present" or "expected graduation" or "ongoing". 
                - education: list of actual education entries including degree, school, date range, and location if available. Do not infer or generate statuses like "Graduated", "Ongoing", or "Expected" and if the educational background is so many, please organize it like this (dont include the program here):
                    New Era University
                    09/2020 - 08/2024, Quezon City

                    New Era University
                    09/2018 - 07/2020, Quezon City

                Do not return null values. If a field is not present in the resume, simply omit it from the JSON.

                Ensure all values are human-readable. If a field is not found, return null. Do not add extra commentary or explanation. JSON output only.

                Return valid JSON only — no markdown formatting, no explanations, no commentary.'
            ],
            [
                'role' => 'user',
                'content' => $rawText
            ]
        ],
        'temperature' => 0.2
    ];

    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 && isset($decoded['error'])) {
        echo "<pre>OpenAI Error:\n" . json_encode($decoded['error'], JSON_PRETTY_PRINT) . "</pre>";
        return '';
    }

    return $decoded['choices'][0]['message']['content'] ?? '';
}

// ✅ ADDED: Helper function to safely normalize array fields to string
function normalizeToText($field) {
    if (is_array($field)) {
        // Flatten nested arrays recursively
        $flat = array();
        array_walk_recursive($field, function($item) use (&$flat) {
            $flat[] = $item;
        });
        $field = implode(', ', $flat);
    }
    return trim($field) !== '' ? $field : null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['resume_file'])) {
    $file = $_FILES['resume_file'];
    $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $uploadDir = 'uploads/resumes/';
    $filename = uniqid() . '-' . basename($file['name']);
    $filePath = $uploadDir . $filename;

    if (!in_array($file['type'], $allowed)) {
        echo "Unsupported file type. Only PDF and image files allowed.";
        exit;
    }

    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        echo "Failed to upload file.";
        exit;
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $rawText = '';

    try {
        if ($ext === 'pdf') {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $rawText = trim($pdf->getText());

            if (strlen($rawText) < 50) {
                $tempImagePath = sys_get_temp_dir() . '/' . uniqid('resume_page_') . '.jpg';
                $convertCmd = "magick -density 300 " . escapeshellarg($filePath) . "[0] -quality 100 " . escapeshellarg($tempImagePath);
                exec($convertCmd, $imgOut, $imgReturn);

                if ($imgReturn !== 0 || !file_exists($tempImagePath)) {
                    echo "ImageMagick failed to convert PDF to image.<br>";
                    echo "<pre>Command: $convertCmd\nOutput:\n" . print_r($imgOut, true) . "</pre>";
                    exit;
                }

                $ocrOutput = tempnam(sys_get_temp_dir(), 'ocr_pdfimg_');
                $ocrTxtFile = $ocrOutput . '.txt';
                $tesseractPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
                $tesseractCmd = "\"$tesseractPath\" " . escapeshellarg($tempImagePath) . " " . escapeshellarg($ocrOutput) . " -l eng";
                exec($tesseractCmd, $ocrOut, $ocrCode);

                if ($ocrCode !== 0 || !file_exists($ocrTxtFile)) {
                    echo "Tesseract failed on converted image.<br>";
                    echo "<pre>Command: $tesseractCmd\nOutput:\n" . print_r($ocrOut, true) . "</pre>";
                    exit;
                }

                $rawText = file_get_contents($ocrTxtFile);
                unlink($tempImagePath);
                unlink($ocrTxtFile);
            }
        } else {
            $ocrOutput = tempnam(sys_get_temp_dir(), 'ocr_img_');
            $outputTxt = $ocrOutput . '.txt';
            $tesseractPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
            exec("\"$tesseractPath\" " . escapeshellarg($filePath) . " " . escapeshellarg($ocrOutput) . " -l eng", $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputTxt)) {
                echo "OCR failed. Tesseract could not generate output.<br>";
                echo "<pre>Return code: $returnCode\nOutput:\n" . print_r($output, true) . "</pre>";
                exit;
            }

            $rawText = file_get_contents($outputTxt);
            unlink($outputTxt);
        }
    } catch (Exception $e) {
        echo "Text extraction failed.";
        exit;
    }

    // Analyze with GPT
    $parsedJson = analyzeWithGPT($rawText);

    // Clean and decode JSON if wrapped in markdown (FIXED)
    $cleanJson = trim($parsedJson);
    $cleanJson = preg_replace('/^```(?:json)?|```$/m', '', $cleanJson);
    $parsedData = json_decode($cleanJson, true);

    // ✅ ADDED: Normalize array fields to safe strings using helper
    $phone = $parsedData['phone'] ?? null;
    $homeAddress = $parsedData['home_address'] ?? null;
    $skills = normalizeToText($parsedData['skills'] ?? null);
    $education = normalizeToText($parsedData['education'] ?? null);
    $experience = normalizeToText($parsedData['experience'] ?? null);
    $program = $parsedData['program'] ?? null;
    $educationStatus = $parsedData['education_status'] ?? null;

    // Insert into resume_history
    $stmt = $conn->prepare("INSERT INTO resume_history 
        (user_id, original_filename, raw_text, parsed_json, pdf_file, phone, home_address, skills, education, experience, program, education_status) 
        VALUES 
        (:user_id, :original_filename, :raw_text, :parsed_json, :pdf_file, :phone, :home_address, :skills, :education, :experience, :program, :education_status)");
    $stmt->execute([
        ':user_id' => $userId,
        ':original_filename' => $file['name'],
        ':raw_text' => $rawText,
        ':parsed_json' => $parsedJson,
        ':pdf_file' => $filename,
        ':phone' => $phone,
        ':home_address' => $homeAddress,
        ':skills' => $skills,
        ':education' => $education,
        ':experience' => $experience,
        ':program' => $program,
        ':education_status' => $educationStatus
    ]);

    // Update resume_info (latest)
    $conn->prepare("DELETE FROM resume_info WHERE user_id = :user_id")->execute([':user_id' => $userId]);

    $stmt = $conn->prepare("INSERT INTO resume_info 
        (user_id, raw_text, parsed_json, pdf_file, phone, home_address, skills, education, experience, program, education_status) 
        VALUES 
        (:user_id, :raw_text, :parsed_json, :pdf_file, :phone, :home_address, :skills, :education, :experience, :program, :education_status)");
    $stmt->execute([
        ':user_id' => $userId,
        ':raw_text' => $rawText,
        ':parsed_json' => $parsedJson,
        ':pdf_file' => $filename,
        ':phone' => $phone,
        ':home_address' => $homeAddress,
        ':skills' => $skills,
        ':education' => $education,
        ':experience' => $experience,
        ':program' => $program,
        ':education_status' => $educationStatus
    ]);

    echo "Resume uploaded and saved successfully.";
} else {
    echo "No file received.";
}
