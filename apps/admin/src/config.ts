/**
 * Business numbers the portal needs for display. The API decides every rule;
 * these copies only label screens. Each mirrors apps/api/config/groupbuy.php,
 * so change both together.
 */

/** groupbuy.display_timezone: times are stored in UTC and shown in Riyadh time. */
export const DISPLAY_TIMEZONE = 'Asia/Riyadh';

/** groupbuy.payments.expiry_alert_days: holds this close to expiry are flagged. */
export const EXPIRY_ALERT_DAYS = 3;

/** groupbuy.campaigns.duration_days: the deadline is set at approval to start + 7 days. */
export const CAMPAIGN_DURATION_DAYS = 7;

/** API base path. Same origin by default; Vite proxies it in development. */
export const API_BASE_PATH: string = import.meta.env.VITE_API_BASE_URL ?? '/api/v1';
