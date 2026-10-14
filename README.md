# ProfitKoro Connector

This WordPress plugin connects your WooCommerce store to [ProfitKoro](https://profitkoro.com). Orders that come from ProfitKoro affiliates are then tracked automatically.

**[Download the plugin](https://github.com/ChaarbitOrg/profitkoro-connector/releases/latest/download/profitkoro-connector.zip)**

## What you need

- A WordPress site with WooCommerce installed and turned on
- A ProfitKoro merchant account

Your site should run WordPress 6.8 or newer, WooCommerce 9.0 or newer and PHP 8.0 or newer. If you're not sure, your hosting company can tell you.

## 1. Install the plugin

1. Download **profitkoro-connector.zip** from the link above. Don't unzip it.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the file, click **Install Now**, then click **Activate Plugin**.

## 2. Connect your store

1. In WordPress, go to **WooCommerce → ProfitKoro Connector**.
2. In your ProfitKoro merchant panel, open **Store connection** and copy your **Store key** and **Signing secret**.
3. Paste both into the plugin and click **Continue**.
4. Wait a few seconds. When you see **Congratulations!**, click **Finish**. That's it.

If ProfitKoro doesn't accept the details, the plugin tells you straight away and saves nothing. Copy them again and try once more.

## Day to day

Once your store is connected, there's nothing else to do. Orders from affiliate links are sent to ProfitKoro on their own, including status changes, refunds, item edits and deleted orders.

- **Overview** shows visitors who came from an affiliate link, the orders they placed, and every order update sent to ProfitKoro.
- **Settings** shows your store key and signing secret. You can also run setup again or reset the plugin here.
- **Selling physical products?** Open the order and paste the courier's tracking link into the **ProfitKoro Attribution** box.

If something goes wrong, for example setup isn't finished or your store can't reach ProfitKoro, a warning appears at the top of your WordPress dashboard with a link to fix it.

## Updating

Download the new **profitkoro-connector.zip** and upload it the same way as before. When WordPress asks, choose **Replace current with uploaded**. Your settings are kept.

## Something not working?

| What you see | What to do |
| --- | --- |
| Your details were rejected | Copy the store key and signing secret again from **Store connection**, then open **Settings → Run setup**. |
| ProfitKoro can't be reached | Ask your hosting company to let your site connect to **profitkoro.com** and to check that the server time is correct. |
| An order is missing in ProfitKoro | Only orders from affiliate links are sent. Check **Sent events** on the **Overview** tab. |

Still stuck? Contact ProfitKoro support and include the message you see. Never share your signing secret with anyone.
