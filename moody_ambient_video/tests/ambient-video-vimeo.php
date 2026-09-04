<?php

require dirname(__DIR__) . '/moody_ambient_video.module';

assert(moody_ambient_video_vimeo_url('https://player.vimeo.com/video/1220604680') === 'https://player.vimeo.com/video/1220604680?background=1&dnt=1');
assert(moody_ambient_video_vimeo_url('https://player.vimeo.com/video/1220604680?h=abc') === 'https://player.vimeo.com/video/1220604680?h=abc&background=1&dnt=1');
assert(moody_ambient_video_vimeo_url('https://example.com/video.mp4') === NULL);
