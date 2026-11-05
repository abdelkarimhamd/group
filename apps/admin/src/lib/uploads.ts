/**
 * Upload limits, mirrored from apps/api/config/groupbuy.php
 * (media.image_max_kb and media.attachment_max_kb). The server decides;
 * these only spare the user an upload it would refuse. Change both together.
 */
export const MAX_IMAGE_BYTES = 10240 * 1024;
export const MAX_ATTACHMENT_BYTES = 20480 * 1024;
