<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation to Join UU Mission</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #f5f5f5; padding: 30px; border-radius: 8px;">
        <h1 style="color: #1e40af; margin-top: 0;">You're Invited to Join UU Mission</h1>
        
        <p>Hello,</p>
        
        <p>You have been invited to join the Uganda Union Mission platform. This invitation will allow you to create your account and access the missionary management system.</p>
        
        <p><strong>Important:</strong> This invitation link will expire on <strong>{{ $expiresAt }}</strong>.</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $acceptanceUrl }}" style="display: inline-block; background: #1e40af; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;">
                Accept Invitation
            </a>
        </div>
        
        <p style="font-size: 12px; color: #666;">
            If you cannot click the button above, copy and paste this link into your browser:<br>
            <a href="{{ $acceptanceUrl }}" style="color: #1e40af;">{{ $acceptanceUrl }}</a>
        </p>
        
        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
        
        <p style="font-size: 12px; color: #666;">
            If you did not expect this invitation, you can safely ignore this email.
        </p>
        
        <p style="font-size: 12px; color: #666;">
            Uganda Union Mission<br>
            East-Central Africa Division
        </p>
    </div>
</body>
</html>
