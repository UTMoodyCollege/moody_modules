"use strict";

var gulp = require("gulp");
var sass = require("gulp-sass");
var uglify = require("gulp-uglify");
var autoprefixer = require("gulp-autoprefixer");
var gulpStylelint = require("gulp-stylelint");
var csscombx = require("gulp-csscombx");

gulp.task("sass", function () {
  return gulp.src("./src/scss/build/**/*.scss")
    .pipe(sass({outputStyle: "expanded"}).on("error", sass.logError))
    .pipe(autoprefixer())
    .pipe(csscombx())
    .pipe(gulp.dest("css"));
});

gulp.task('copy-js', function () {
  return gulp.src("./src/js/**/*.js")
    .pipe(uglify())
    .pipe(gulp.dest('js'));
});

gulp.task("default", gulp.series("sass", 'copy-js'));
