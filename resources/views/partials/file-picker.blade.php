{{--
    The file picker: a drop zone around the real input, a list of what is
    chosen, a preview of every picture, and an × to take one back out.

    Used by the filing form and by "Send documents" on a concern. It was
    only on the first, so a student sending the receipt they were asked
    for got the bare browser control: no way to see which screenshot they
    had picked, and no way to remove one without starting over.

    Parameters:
      $name      the input's name, e.g. attachments[] or documents[]
      $inputId   the input's id -- what the <label for> points at
      $zoneId, $browseId, $listId, $noteId   the parts around it
      $errorKey  the validation key, e.g. attachments or documents

    The input is still what submits. Everything below only decides what is
    in it.
--}}
<div class="dropzone" id="{{ $zoneId }}">
    <input type="file" name="{{ $name }}" id="{{ $inputId }}" multiple
           accept="{{ collect(config('concerns.attachment_extensions'))->map(fn ($e) => '.'.$e)->implode(',') }}"
           class="dropzone-input">

    <p class="dropzone-lead">
        Drag files here, or <button type="button" class="dropzone-browse" id="{{ $browseId }}">choose files</button>
    </p>
    <p class="dropzone-sub">Pictures, video, sound recordings, documents or a ZIP</p>
</div>

<ul class="file-list" id="{{ $listId }}" hidden></ul>
<p class="file-note" id="{{ $noteId }}" hidden></p>

@error($errorKey)
    <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $message }}</div>
@enderror
@foreach ($errors->get($errorKey.'.*') as $fileErrors)
    @foreach ($fileErrors as $msg)
        <div style="color: #dc3545; font-size: 0.85rem; margin-top: 0.25rem;">{{ $msg }}</div>
    @endforeach
@endforeach

<script>
    // The chosen files, and the only writer of the input's FileList.
    //
    // A FileList cannot be edited, which is why the native control has
    // no remove button: the browser hands you a frozen list and the
    // only way to change it is to pick again. DataTransfer is the way
    // back -- build one, add the files to keep, and assign its list to
    // the input.
    (function () {
        var zone = document.getElementById(@json($zoneId));
        var input = document.getElementById(@json($inputId));
        var browse = document.getElementById(@json($browseId));
        var list = document.getElementById(@json($listId));
        var note = document.getElementById(@json($noteId));

        if (!zone || !input || !list) return;

        var MAX_FILES = {{ (int) config('concerns.max_attachments') }};
        var MAX_BYTES = {{ (int) config('concerns.max_attachment_mb') }} * 1024 * 1024;

        var chosen = [];

        // A picture is shown before it is sent, so a student can see WHICH
        // screenshot they picked -- "Screenshot (2).png" says nothing. Only
        // the kinds a browser can draw; a HEIC or TIFF still sends, it just
        // shows its name.
        var DRAWABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        var previews = new Map();

        function previewUrl(file) {
            if (DRAWABLE.indexOf(file.type) === -1) return null;
            if (!previews.has(file)) previews.set(file, URL.createObjectURL(file));
            return previews.get(file);
        }

        function forget(file) {
            if (previews.has(file)) {
                URL.revokeObjectURL(previews.get(file));
                previews.delete(file);
            }
        }

        function readable(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
            return bytes + ' B';
        }

        // Same name AND same size: the browser gives no id, and a
        // student dropping the same photo twice means it once.
        function already(file) {
            return chosen.some(function (f) {
                return f.name === file.name && f.size === file.size;
            });
        }

        function sync() {
            var transfer = new DataTransfer();
            chosen.forEach(function (f) { transfer.items.add(f); });
            input.files = transfer.files;
        }

        function render(message) {
            list.textContent = '';

            chosen.forEach(function (file, index) {
                var row = document.createElement('li');
                row.className = 'file-row' + (file.size > MAX_BYTES ? ' is-too-big' : '');

                // The picture itself, opening full size in a new tab.
                var url = previewUrl(file);
                if (url) {
                    var view = document.createElement('a');
                    view.className = 'file-thumb';
                    view.href = url;
                    view.target = '_blank';
                    view.rel = 'noopener';
                    view.title = 'View ' + file.name;

                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = '';
                    view.appendChild(img);
                    row.appendChild(view);
                }

                var name = document.createElement('span');
                name.className = 'file-name';
                name.textContent = file.name;

                var size = document.createElement('span');
                size.className = 'file-size';
                size.textContent = readable(file.size)
                    + (file.size > MAX_BYTES ? ' — too large' : '');

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'file-remove';
                remove.setAttribute('aria-label', 'Remove ' + file.name);
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    forget(file);
                    chosen.splice(index, 1);
                    sync();
                    render('');
                });

                row.appendChild(name);
                row.appendChild(size);
                row.appendChild(remove);
                list.appendChild(row);
            });

            list.hidden = chosen.length === 0;

            note.textContent = message || '';
            note.hidden = !message;
        }

        function add(files) {
            var full = false;

            Array.prototype.forEach.call(files, function (file) {
                if (already(file)) return;

                if (chosen.length >= MAX_FILES) {
                    full = true;
                    return;
                }

                chosen.push(file);
            });

            sync();

            var tooBig = chosen.filter(function (f) { return f.size > MAX_BYTES; }).length;
            var message = '';

            if (full) {
                message = 'Only ' + MAX_FILES + ' files can be attached, so the rest were left out.';
            } else if (tooBig > 0) {
                // Said here rather than after submitting, because a file
                // over the server's own limit never reaches the app --
                // the request is refused whole and the page comes back
                // blank with nothing to explain it.
                message = tooBig === 1
                    ? 'One file is too large to send. Remove it before submitting.'
                    : tooBig + ' files are too large to send. Remove them before submitting.';
            }

            render(message);
        }

        browse.addEventListener('click', function () { input.click(); });

        input.addEventListener('change', function () {
            add(input.files);
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
                event.preventDefault();
                zone.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
                event.preventDefault();
                if (name === 'dragleave' && zone.contains(event.relatedTarget)) return;
                zone.classList.remove('is-over');
            });
        });

        zone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files.length) {
                add(event.dataTransfer.files);
            }
        });

        // Dropping anywhere else on the page would otherwise make the
        // browser navigate away from a half-filled form.
        ['dragover', 'drop'].forEach(function (name) {
            window.addEventListener(name, function (event) {
                if (!zone.contains(event.target)) event.preventDefault();
            });
        });
    })();
</script>
