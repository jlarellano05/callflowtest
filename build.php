<?php
// 1. Ensure an output directory exists (e.g., 'public' or 'dist')
if (!is_dir('public')) {
    mkdir('public', 0755, true);
}

// 2. Fetch or prepare layout templates and content
$template = file_get_contents('src/template.html');
$content = file_get_contents('src/index.php'); 

// 3. Render dynamic content or process data
$output = str_replace('{{CONTENT}}', $content, $template);

// 4. Save the generated static HTML to the output directory
file_put_contents('public/index.html', $output);

echo "Build completed successfully!";