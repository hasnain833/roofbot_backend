<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100% !important;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            background-color: #f0f4f8;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        img {
            border: 0;
            outline: none;
            text-decoration: none;
            -ms-interpolation-mode: bicubic;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f0f4f8;
            padding-bottom: 40px;
        }
        .main {
            background-color: #ffffff;
            margin: 0 auto;
            width: 100%;
            max-width: 600px;
            border-spacing: 0;
            font-family: sans-serif;
            color: #1a202c;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }
        .header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            padding: 50px 20px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            font-size: 28px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }
        .accent-bar {
            height: 4px;
            width: 40px;
            background-color: #3b82f6;
            margin: 15px auto 0;
            border-radius: 2px;
        }
        .content {
            padding: 50px 40px;
            background-color: #ffffff;
        }
        .content p {
            font-size: 16px;
            line-height: 1.8;
            color: #4a5568;
            margin-bottom: 25px;
            white-space: pre-line;
        }
        .content strong {
            color: #1a202c;
        }
        .footer {
            background-color: #f8fafc;
            padding: 40px 20px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            font-size: 14px;
            color: #718096;
            margin: 8px 0;
            line-height: 1.5;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
        }
        .social-links {
            margin-bottom: 20px;
        }
        .social-links a {
            margin: 0 10px;
            display: inline-block;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 40px 20px !important;
            }
            .header {
                padding: 40px 20px !important;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div style="height: 40px;">&nbsp;</div>
        <table class="main" width="100%">
            <tr>
                <td class="header">
                    <h1>{{ $company_name }}</h1>
                    <div class="accent-bar"></div>
                </td>
            </tr>
            <tr>
                <td class="content">
                    <div style="font-size: 16px;">
                        {!! nl2br(e($body)) !!}
                    </div>
                </td>
            </tr>
            <tr>
                <td class="footer">
                    <p style="font-weight: 700; color: #1e293b; font-size: 16px; margin-bottom: 15px;">{{ $company_name }}</p>
                    @if($company_domain)
                        <p>Website: <a href="https://{{ $company_domain }}">{{ $company_domain }}</a></p>
                    @endif
                    @if($company_phone)
                        <p>Phone: <a href="tel:{{ $company_phone }}" style="color: #718096; text-decoration: none;">{{ $company_phone }}</a></p>
                    @endif
                    <div style="height: 20px;"></div>
                    <p>&copy; {{ date('Y') }} {{ $company_name }}. All rights reserved.</p>
                    <p style="font-size: 12px; color: #a0aec0; margin-top: 20px;">
                        This is an automated message from {{ $company_name }}. Please do not reply directly to this email.
                    </p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
