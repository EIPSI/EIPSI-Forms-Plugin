<?php
namespace EIPSI\ExternalApi;
/** WP-CLI is a trusted local host interface; no API key or impersonated WP user. */
final class OperationsCli {
    public function status(): void {\WP_CLI::line(wp_json_encode(ExternalApiHealth::inspect()));}
    public function maintenance(): void {\WP_CLI::line(wp_json_encode((new Operations())->maintenance()));}
    public function cleanup(): void {\WP_CLI::line(wp_json_encode((new Operations())->maintenance(true)));}
}
\WP_CLI::add_command('eipsi api',OperationsCli::class);
