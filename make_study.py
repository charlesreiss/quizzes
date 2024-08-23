import os
import re
import subprocess
import sys

def header(title):
    template = """ 
    <html>
    <head>
        <title>TITLE</title>
        <link rel="icon" type="image/svg+xml" href="favicon.svg">
        <link rel="stylesheet" href="style.css">
        <?php if (isset($_GET['view_only'])) { ?>
        <style>
            body { background:#fdb; }
            .directions, .multiquestion { background: #fed;  }
        </style>
        <?php
        }
        ?>
        
        <link rel="stylesheet" href="katex/katex.min.css">
        <script type="text/javascript" src="katex/katex.min.js"></script>
        <script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function() {
                document.querySelectorAll('span.mymath').forEach(x => 
                    katex.render(x.textContent, x, 
                        {throwOnError:false, displayMode:false})
                )
                document.querySelectorAll('div.mymath').forEach(x => 
                    katex.render(x.textContent, x, 
                        {throwOnError:false, displayMode:true})
                )
            });
        </script>
    </head>
    <body>
    <h1>TITLE</h1>
    """
    template = template.replace("TITLE", title)
    print(template)

def footer():
    print("""
    </body>
    </html>
    """)

def run_quizzes(options, qids):
    for qid in sorted(qids):
        text = subprocess.check_output([
            'php',
            'quiz.php',
            'standalone=1',
            'view_only=1',
            'qid={}'.format(qid),
        ] + options, env={'PHP_AUTH_USER': 'quiz_viewer_for_study_guide'}, encoding='UTF-8') 
        text = text.replace('<!DOCTYPE html>', '')
        print(text)


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--key', action='store_true')
    parser.add_argument('--title', required=True)
    parser.add_argument('qids', nargs='+')
    args = parser.parse_args()
    if args.key:
        options = ['showkey=1']
    else:
        options = []
    header(args.title)
    run_quizzes(options, args.qids)
    footer()

