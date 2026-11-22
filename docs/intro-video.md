# The intro video

The home page shows a short narrated video to visitors who are not signed in
and have not seen it yet. They can skip it at any time. Signed-in users find a
link to it in "Getting started".

## Where it lives

| File                                          | What it is                                            |
| --------------------------------------------- | ----------------------------------------------------- |
| `resources/media/intro.mp4`                   | The video: 1280x720, 72 seconds, H.264 with AAC sound |
| `resources/media/intro.vtt`                   | English captions, one cue per spoken sentence         |
| `resources/media/intro-poster.jpg`            | The picture shown before the video starts             |
| `resources/js/components/site/IntroVideo.vue` | The player with Play, Sound and Skip                  |
| `resources/js/pages/Welcome.vue`              | Decides when the video shows                          |

Vite copies the three media files to `public/build/assets` with a hash in the
name, so they are served like every other built file.

## How it behaves

- First visit as a guest: the video starts by itself, without sound, with
  captions. Browsers do not allow a video to start with sound on its own.
  "Sound on" turns the voice on.
- "Skip intro", or the end of the video, hides it. The browser remembers that
  in local storage (`sprintlab.intro.seen`); the privacy page mentions this.
- "Watch the intro" on the home page, or `/?intro=1`, shows it again, with
  sound.
- With "reduce motion" switched on in the operating system, nothing starts by
  itself.

## How it was made

The pictures are HTML scenes in the colours of the app, rendered frame by frame
with a headless browser and joined with ffmpeg. The voice is synthetic:
[Kokoro](https://github.com/hexgrad/kokoro) (model `kokoro-multi-lang-v1_0`,
voice `af_heart`, Apache 2.0 licence), run with
[sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx) (Apache 2.0). No music.

## Replacing it

Put a new `intro.mp4`, `intro.vtt` and `intro-poster.jpg` in `resources/media`,
run `npm run build` and commit `public/build`. Keep the video under 6 MB (a
test checks that) and keep the captions in step with the voice.
