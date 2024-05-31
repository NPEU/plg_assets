/*

*/

(function() {

    var tidy_media_views = function () {
        //console.log('tidy_media_views');

        const com_media = document.getElementById('com-media');

        if (com_media) {
            // Watch this for changes and then fix all visible filenames:
            const config = { attributes: false, childList: true, subtree: true };

            const callback = (mutationList, observer) => {
                //console.log("com-media has changed");
                let params = new URLSearchParams(document.location.search);
                let path = params.get("path").replace('local-assets:', '/assets');

                // I'm not sure if it's best to operate on each mutation to do the fixed, or to do
                // it on the altered container as a whole?
                // 2nd option would run the changes per-file, would this be very slow?

                var elements_1 = com_media.querySelectorAll('.media-browser-item > :not(.media-browser-item-directory)');
                //console.log(path);
                Array.prototype.forEach.call(elements_1, function (el, i) {
                    // Check for type of item:
                    var item_container = el.querySelector('.media-browser-item-preview');
                    var filename = item_container.getAttribute('title');
                    var extension = filename.split('.').pop();
                    //console.log(extension);
                    if (!extension.match(/(jpg|png)/)) {
                        el.classList.add('is_file');
                        el.dataset.filetype = extension;
                    }

                    // Look to see if the image is set or not:
                    var preview_container = item_container.querySelector('.image-background');
                    //
                    //return;
                    if (preview_container) {

                        //preview_container.classList.add('image-background');
                        var img = preview_container.querySelector('img');
                        if (!img) {
                            let info_container = el.querySelector('.media-browser-item-info');
                            let file = path + '/' + info_container.textContent.trim() + '.png';
                            //console.log(info_container.textContent);
                            var width = '100';
                            var height = '100';

                            let img = `<img
                                class="image-cropped"
                                src="${file}"
                                alt="${el.title}"
                                loading="lazy"
                                width="${width}"
                                height="${height}"
                            >`;
                            preview_container.innerHTML = img;

                            /*
                            // Get image info:
                            // UPDATE - note this was done BEFORE innerHTML but prefomed very badly
                            // when there are a lot of files.The code used id below. It should be
                            // possible to just do the innerHMTML then go back through and update
                            // width/heigh, but there doesn't seem much point as those attributes
                            // are not acually doing anything for the display (that I can see)
                            var json_url = window.location.origin + file + '.preview.json';

                            fetch(json_url)
                                .then((response) => {
                                    if (!response.ok) {
                                        throw new Error(`HTTP error: ${response.status}`);
                                    }
                                    return response.text();
                                })
                                .then((text) => {
                                    img_info = JSON.parse(text);
                                    width = img_info.image_width;
                                    height = img_info.image_height;

                                    let img = `<img
                                        class="image-cropped"
                                        src="${file}"
                                        alt="${el.title}"
                                        loading="lazy"
                                        width="${width}"
                                        height="${height}"
                                    >`;
                                    container.innerHTML = img;
                                })
                                .catch((error) => {
                                    console.warn(error);
                                });
                            */
                        }
                    }
                })

            };

            const observer = new MutationObserver(callback);
            observer.observe(com_media, config);
        }
    };

    const querySelectorFrom = (selector, elements) => {
        const elementsArr = [...elements];
        return [...document.querySelectorAll(selector)].filter(elm => elementsArr.includes(elm));
    }

    const ready = function(fn) {
        if (document.attachEvent ? document.readyState === "complete" : document.readyState !== "loading") {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(tidy_media_views);
})();
