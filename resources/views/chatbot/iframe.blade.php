<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $companyName }} Chatbot</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        html, body, #root { margin:0; padding:0; height:100%; width:100%; }
    </style>
</head>
<body>
    <div id="root"></div>
    <script>
        // Pass bot token and company name to React app
        window.CHATBOT_CONFIG = {
            companyName: "{{ $companyName }}",
            botToken: "{{ $botToken }}"
        };
    </script>
    <script src="{{ mix('js/chatbot.js') }}"></script> <!-- Your compiled React chatbot bundle -->
</body>
</html>
