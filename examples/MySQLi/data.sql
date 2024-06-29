CREATE TABLE schools (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR (255) NOT NULL,
    type ENUM ('elementary', 'secondary', 'university') NOT NULL
);

INSERT INTO schools (id, name, type)
VALUES (1, 'Foo elementary school', 'elementary'), (2, 'Bar secondary school', 'secondary'), (3, 'Baz university', 'university');

CREATE TABLE people (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR (255) NOT NULL
);

INSERT INTO people (id, name)
VALUES (1, 'John Doe'), ('2', 'Jane Doe'), (3, 'John Doe jr.'), (4, 'Jane Doe jr.');

CREATE TABLE classes (
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    school_id INT NOT NULL REFERENCES schools (id),
    teacher_id INT NULL REFERENCES people (id)
);

INSERT INTO classes (id, name, school_id, teacher_id)
VALUES (1, 'History', 1, 1), (2, 'Math', 1, 1), (3, 'PHP Programming', 2, 2), (4, 'Algorithms and Data Structures', 3, 2);

CREATE TABLE class_students (
    class_id INT NOT NULL REFERENCES people (id),
    student_id INT NOT NULL REFERENCES people (id)
);

INSERT INTO class_students (class_id, student_id)
VALUES (1, 1), (2, 1), (3, 2);
