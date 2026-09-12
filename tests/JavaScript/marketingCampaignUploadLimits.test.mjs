import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

test("nginx accepts the full marketing campaign image payload", () => {
	const nginxConfig = readFileSync("docker/nginx/default.conf", "utf8");

	assert.match(nginxConfig, /client_max_body_size\s+40m;/);
});

test("php upload limits match marketing campaign image validation", () => {
	const dockerfile = readFileSync("Dockerfile", "utf8");

	assert.match(dockerfile, /upload_max_filesize=5M/);
	assert.match(dockerfile, /post_max_size=40M/);
	assert.match(dockerfile, /max_file_uploads=20/);
});
