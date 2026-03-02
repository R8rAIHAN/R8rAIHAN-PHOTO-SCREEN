<?php
// Environment Variables
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('GOOGLE_VISION_KEY', getenv('GOOGLE_VISION_KEY'));

$telegramUrl = "https://api.telegram.org/bot".BOT_TOKEN."/";

// টেলিগ্রাম থেকে ডাটা গ্রহণ
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$chatId = $update['message']['chat']['id'] ?? null;
$text = $update['message']['text'] ?? '';
$photo = $update['message']['photo'] ?? [];

function sendMessage($chatId, $text) {
    global $telegramUrl;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => false];
    file_get_contents($telegramUrl.'sendMessage?'.http_build_query($data));
}

// কমান্ড হ্যান্ডলিং
if ($text == '/start') {
    sendMessage($chatId, "🔍 <b>Image Scanner Bot Active!</b>\n\nসোশ্যাল প্রোফাইল খুঁজে পেতে একটি পরিষ্কার ছবি পাঠান।");
} 

elseif (!empty($photo)) {
    // সবচেয়ে বড় সাইজের ছবি নেওয়া
    $fileId = $photo[count($photo)-1]['file_id'];
    $getFile = json_decode(file_get_contents($telegramUrl.'getFile?file_id='.$fileId), true);
    $filePath = $getFile['result']['file_path'];
    $imageUrl = 'https://api.telegram.org/file/bot'.BOT_TOKEN.'/'.$filePath;
    
    sendMessage($chatId, "🔄 <b>ছবি অ্যানালাইজ হচ্ছে...</b>\nগুগল ডাটাবেসে প্রোফাইল খোঁজা হচ্ছে। দয়া করে অপেক্ষা করুন।");
    
    // Google Vision API Call
    $base64Image = base64_encode(file_get_contents($imageUrl));
    $visionUrl = 'https://vision.googleapis.com/v1/images:annotate?key='.GOOGLE_VISION_KEY;
    $visionData = [
        'requests' => [[
            'image' => ['content' => $base64Image],
            'features' => [
                ['type' => 'WEB_DETECTION', 'maxResults' => 30] // রেজাল্ট সংখ্যা বাড়ানো হয়েছে
            ]
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
    
    $foundLinks = [];

    // ১. সরাসরি ম্যাচ করা পেজ থেকে লিংক খোঁজা
    if (isset($web['pagesWithMatchingImages'])) {
        foreach ($web['pagesWithMatchingImages'] as $page) {
            $url = $page['url'];
            if (preg_match('/instagram\.com|facebook\.com|twitter\.com|linkedin\.com|tiktok\.com|x\.com|pinterest\.com/', $url)) {
                $foundLinks[] = $url;
            }
        }
    }

    // ২. ভিজ্যুয়ালি সিমিলার ইমেজ থেকে লিংক খোঁজা
    if (isset($web['visuallySimilarImages'])) {
        foreach ($web['visuallySimilarImages'] as $img) {
            $url = $img['url'];
            if (preg_match('/facebook\.com|instagram\.com/', $url)) {
                $foundLinks[] = $url;
            }
        }
    }

    // ডুপ্লিকেট লিংক বাদ দেওয়া
    $foundLinks = array_unique($foundLinks);

    if (!empty($foundLinks)) {
        $resultText = "🎯 <b>সম্ভাব্য সোশ্যাল প্রোফাইল পাওয়া গেছে:</b>\n\n";
        foreach (array_slice($foundLinks, 0, 10) as $link) { // সেরা ১০টি রেজাল্ট দেখানো
            $platform = parse_url($link, PHP_URL_HOST);
            $resultText .= "✅ " . strtoupper(str_replace('www.', '', $platform)) . "\n🔗 <a href='$link'>Click to View Profile</a>\n\n";
        }
        sendMessage($chatId, $resultText);
    } else {
        // যদি সরাসরি লিংক না পাওয়া যায়, তবে কিওয়ার্ড দেখানো
        $entities = "";
        if (isset($web['webEntities'])) {
            foreach (array_slice($web['webEntities'], 0, 5) as $entity) {
                if(isset($entity['description'])) $entities .= "• " . $entity['description'] . "\n";
            }
        }
        
        $msg = "❌ সরাসরি কোনো সোশ্যাল লিংক পাওয়া যায়নি।\n\n";
        if ($entities != "") {
            $msg .= "💡 <b>গুগল যা খুঁজে পেয়েছে:</b>\n$entities\n(এই তথ্যগুলো দিয়ে ম্যানুয়ালি সার্চ করতে পারেন।)";
        }
        sendMessage($chatId, $msg);
    }
}
?>
