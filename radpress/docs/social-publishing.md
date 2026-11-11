# Publish pages and posts to social accounts

In **Admin → Social Publishing**, the site owner can verify one destination per
platform. Obtain a user or Page access token through your provider’s developer
app, paste it into the corresponding form, and select **Verify and connect**.
Check the displayed account before using it from an editor. Replace a token when
it expires. Disconnect removes the stored credential from Press. Revoke the
token at the provider to end its external access.

| Platform | Account and permissions |
| --- | --- |
| Facebook | A Page access token for the destination Page with `pages_manage_posts` and `pages_read_engagement`. See [Meta’s Pages publishing guide](https://developers.facebook.com/docs/pages-api/posts/). |
| Instagram | An Instagram Login token for a Business or Creator account with `instagram_business_basic` and `instagram_business_content_publish`. See [Meta’s Instagram API](https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api). |
| X | An OAuth 2.0 user access token with `users.read`, `tweet.read` and `tweet.write`. See [X’s post endpoint](https://docs.x.com/x-api/posts/create-post). |
| LinkedIn | A member account token with `openid`, `profile` and `w_member_social`. Enable the Sign In and Share on LinkedIn products. See [LinkedIn’s access guide](https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access). |
| YouTube | A user OAuth token for the selected channel with `youtube.readonly` and `youtube.upload`. Enable YouTube Data API v3. Google restricts uploads from unverified API projects to private viewing. See [YouTube’s upload endpoint](https://developers.google.com/youtube/data/reference/rest/v3/videos/insert) and [developer policies](https://developers.google.com/youtube/terms/developer-policies). |

Connection verifies the account identity. A provider can still reject publishing
if the developer app, credential, or destination lacks write access. Press needs
PHP cURL, private writable data storage, and Sodium or OpenSSL for encryption.
Back up `radpress/data/integrations/social-publishing/` together with the
installation’s encryption key. Credentials are encrypted and never displayed
after saving. The YouTube integration also requires PHP Fileinfo.

## Send content

1. Open a page or post that is already public. Save any edits before sharing.
2. Select the platform’s **Publish to** button under **Social publishing**.
   Facebook and LinkedIn include the public link and available summary. X sends
   a short title and link. Instagram needs a publicly reachable HTTPS JPEG
   ending in `.jpg` or `.jpeg` in the social image or featured image field.
   The link goes in the Instagram caption as text. If Instagram is still
   processing the image, wait a minute, then return to the editor and select
   **Check image and publish to Instagram**. Press checks the existing upload.
3. For YouTube, select a local MP4 up to 20 MiB, review the prefilled title and
   description, choose visibility and audience, then select **Upload to YouTube**.
   Web PHP upload limits can impose a smaller cap. YouTube may process the video
   after accepting the upload.
4. Review the result and provider reference. The editor shows the outcome for
   the current saved revision and connected account. Press blocks repeat
   delivery after a successful or uncertain send. Check the provider account
   directly when the outcome is uncertain.

For example, a public page called **Open Studio** at
`https://example.com/events/open-studio` can be sent to X as a title and link.
For Instagram, first save a public JPEG in the page’s social image field.
