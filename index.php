<?php
// Environment Variables থেকে টোকেন নেওয়া (নিরাপদ পদ্ধতি)
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('GOOGLE_VISION_KEY', getenv('GOOGLE_VISION_KEY'));

$telegramUrl = "https://api.telegram.org/bot".BOT_TOKEN."/";

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$chatId = $update['message']['chat']['id'] ?? null;
$text = $update['message']['text'] ?? '';
$photo = $update['message']['photo'] ?? [];

function sendMessage($chatId, $text, $replyMarkup = null) {
    global $telegramUrl;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $replyMarkup];
    file_get_contents($telegramUrl.'sendMessage?'.http_build_query($data));
}

// Main Logic
if ($text == '/start') {
    sendMessage($chatId, "🔍 <b>Image Scanner Bot</b>\n\nএকটি ছবি পাঠান, আমি সেটির সোশ্যাল প্রোফাইল খুঁজে দেখবো।");
} 

elseif (!empty($photo)) {
    $fileId = $photo[count($photo)-1]['file_id'];
    $getFile = json_decode(file_get_contents($telegramUrl.'getFile?file_id='.$fileId), true);
    $filePath = $getFile['result']['file_path'];
    $imageUrl = 'https://api.telegram.org/file/bot'.BOT_TOKEN.'/'.$filePath;
    
    sendMessage($chatId, "🔄 ছবি অ্যানালাইজ হচ্ছে... ৩০ সেকেন্ড অপেক্ষা করুন।");
    
    // Google Vision API Call
    $base64Image = base64_encode(file_get_contents($imageUrl));
    $visionUrl = 'https://vision.googleapis.com/v1/images:annotate?key='.GOOGLE_VISION_KEY;
    $visionData = [
        'requests' => [[
            'image' => ['content' => $base64Image],
            'features' => [['type' => 'WEB_DETECTION', 'maxResults' => 15]]
        ]]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $visionUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($visionData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    $web = $data['responses'][0]['webDetection'] ?? [];
    $results = "";

    // প্রোফাইল লিঙ্ক খুঁজে বের করা
    if (isset($web['pagesWithMatchingImages'])) {
        foreach ($web['pagesWithMatchingImages'] as $page) {
            $url = $page['url'];
            if (preg_match('/instagram\.com|facebook\.com|twitter\.com|linkedin\.com|tiktok\.com|x\.com/', $url)) {
                $results .= "🔗 <a href='$url'>View Profile</a>\n";
            }
        }
    }

    if ($results != "") {
        sendMessage($chatId, "🎯 <b>Social Profiles Found:</b>\n\n" . $results);
    } else {
        sendMessage($chatId, "❌ দুঃখিত, কোনো পাবলিক সোশ্যাল প্রোফাইল পাওয়া যায়নি।");
    }
}
?>
