# Railway reads railway.json for the web service. This Procfile documents both
# roles for any platform that uses one (Heroku, Render, Dokku).
#
# The worker is NOT optional: without it, batches sit at "processing" and no
# mail is ever delivered.
web: entrypoint web
worker: entrypoint worker
